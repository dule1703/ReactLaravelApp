<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * A gzip-compressed dump of the database (7.2). mysqldump is started with an ARRAY of arguments (no
 * shell), the credentials reach it only through a temporary --defaults-extra-file (mode 0600, created
 * empty before it is filled, deleted in finally), never as an argument that `ps` would show.
 *
 * The dump goes to a temporary --result-file (also created 0600 beforehand), is compressed by PHP
 * (gzopen, in chunks) and checked: exit code 0, not smaller than backup.min_dump_bytes and the last line
 * contains "Dump completed" (MySQL and MariaDB both end a finished dump with it). Only then the file gets
 * its final name and the older backups beyond the retention are deleted.
 */
class DatabaseBackup
{
    private const CHUNK = 1048576;

    public function __construct(private readonly BackupStorage $storage) {}

    /**
     * @return array{name: string, path: string, bytes: int, deleted: list<string>}
     *
     * @throws BackupFailed
     */
    public function run(): array
    {
        $connection = $this->connection();

        if (! function_exists('proc_open')) {
            throw new BackupFailed(BackupFailed::PROC_OPEN_UNAVAILABLE);
        }

        $this->storage->directory();
        $this->storage->cleanLeftovers('db');

        $defaults = $dump = $archive = null;

        try {
            $defaults = $this->storage->privateFile($this->storage->tempName('db', 'cnf'));
            $dump = $this->storage->privateFile($this->storage->tempName('db', 'sql'));
            file_put_contents($defaults, $this->defaultsFile($connection));

            try {
                $result = Process::timeout((int) config('backup.timeout'))->run([
                    (string) config('backup.mysqldump'),
                    '--defaults-extra-file='.$defaults, // must be the first option
                    '--result-file='.$dump,
                    '--single-transaction',
                    '--routines',
                    '--triggers',
                    '--skip-lock-tables',
                    '--default-character-set='.($connection['charset'] ?? 'utf8mb4'),
                    $connection['database'],
                ]);
            } catch (Throwable $e) {
                Log::warning('Database backup: mysqldump could not run.', ['reason' => $this->scrub($e->getMessage(), $connection)]);

                throw new BackupFailed(BackupFailed::DUMP_FAILED, 'could not start');
            }

            if ($result->failed()) {
                Log::warning('Database backup: mysqldump failed.', [
                    'exit_code' => $result->exitCode(),
                    'stderr' => Str::limit($this->scrub($result->errorOutput(), $connection), 300, ''),
                ]);

                throw new BackupFailed(BackupFailed::DUMP_FAILED, 'exit code '.$result->exitCode());
            }

            $archive = $this->storage->privateFile($this->storage->tempName('db', 'sql.gz'));
            $this->compress($dump, $archive);

            $name = $this->storage->finalName('db');
            $path = $this->storage->finalize($archive, $name);
            $archive = null;

            return [
                'name' => $name,
                'path' => $path,
                'bytes' => (int) filesize($path),
                'deleted' => $this->storage->prune('db', (int) config('backup.db_keep')),
            ];
        } finally {
            foreach ([$defaults, $dump, $archive] as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function connection(): array
    {
        $connection = config('database.connections.'.(config('backup.connection') ?: config('database.default')));

        if (! is_array($connection) || ! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new BackupFailed(BackupFailed::UNSUPPORTED_DATABASE);
        }

        if (blank($connection['database'] ?? null)) {
            throw new BackupFailed(BackupFailed::CONFIG_INVALID, 'no database name');
        }

        return $connection;
    }

    /** The [client] section of the temporary options file (values quoted; a line break would break out of it). */
    private function defaultsFile(array $connection): string
    {
        $lines = ['[client]'];

        foreach (['user' => 'username', 'password' => 'password', 'host' => 'host', 'socket' => 'unix_socket'] as $option => $key) {
            $value = (string) ($connection[$key] ?? '');

            if ($value === '') {
                continue;
            }

            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new BackupFailed(BackupFailed::CONFIG_INVALID, 'a database setting has a line break');
            }

            $lines[] = $option.'="'.str_replace([chr(92), '"'], [chr(92).chr(92), chr(92).'"'], $value).'"';
        }

        if (($connection['port'] ?? '') !== '') {
            $lines[] = 'port='.(int) $connection['port'];
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * gzip the dump in chunks and check it on the way: size and the "Dump completed" line.
     */
    private function compress(string $dump, string $archive): void
    {
        $in = fopen($dump, 'rb');
        $out = gzopen($archive, 'wb6');

        if ($in === false || $out === false) {
            throw new BackupFailed(BackupFailed::CANNOT_WRITE);
        }

        $total = 0;
        $tail = '';

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);

                if ($chunk === false) {
                    throw new BackupFailed(BackupFailed::CANNOT_WRITE);
                }

                if ($chunk === '') {
                    continue;
                }

                $total += strlen($chunk);
                $tail = substr($tail.$chunk, -4096);

                if (gzwrite($out, $chunk) === false) {
                    throw new BackupFailed(BackupFailed::CANNOT_WRITE);
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }

        if ($total < (int) config('backup.min_dump_bytes')) {
            throw new BackupFailed(BackupFailed::DUMP_TOO_SMALL);
        }

        $lastLine = trim((string) Str::afterLast(rtrim($tail), "\n"));

        if (! str_contains($lastLine, 'Dump completed')) {
            throw new BackupFailed(BackupFailed::DUMP_INCOMPLETE);
        }
    }

    /** Hide everything that identifies the database account before text from the tool reaches the log. */
    private function scrub(string $text, array $connection): string
    {
        foreach (['password', 'username', 'host', 'unix_socket', 'database'] as $key) {
            $value = (string) ($connection[$key] ?? '');

            if ($value !== '') {
                $text = str_replace($value, '***', $text);
            }
        }

        return $text;
    }
}
