<?php

namespace App\Services\Backup;

/**
 * The backup folder (shared/storage/app/backups on the server): created 0700, every file 0600, never
 * inside a folder that the web can reach, and retention that touches only the files of its own kind.
 *
 * Files are made while umask(0177) is active and created EMPTY with mode 0600 before anything is written
 * to them, so there is no moment in which a backup (or a file with a password) is readable by others.
 */
class BackupStorage
{
    /** kind => extension of a finished backup */
    public const EXTENSIONS = ['db' => 'sql.gz', 'files' => 'tar.gz'];

    private ?string $directory = null;

    public function __construct(private readonly string $path, private readonly string $env) {}

    public static function fromConfig(): self
    {
        return new self((string) config('backup.path'), (string) config('app.env'));
    }

    /** The validated folder; it is created (0700) when missing. */
    public function directory(): string
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        if (trim($this->path) === '') {
            throw new BackupFailed(BackupFailed::CONFIG_INVALID, 'empty backup path');
        }

        foreach ([storage_path('app/public'), public_path(), base_path('public')] as $webRoot) {
            if ($this->isInside($this->path, $webRoot)) {
                throw new BackupFailed(BackupFailed::UNSAFE_PATH);
            }
        }

        if (! is_dir($this->path)) {
            $previous = umask(0077);

            try {
                $made = @mkdir($this->path, 0700, true);
            } finally {
                umask($previous);
            }

            if (! $made && ! is_dir($this->path)) {
                throw new BackupFailed(BackupFailed::CANNOT_WRITE);
            }
        }

        @chmod($this->path, 0700);

        if (! is_writable($this->path)) {
            throw new BackupFailed(BackupFailed::CANNOT_WRITE);
        }

        return $this->directory = rtrim($this->path, '/'.chr(92));
    }

    /** Run $callback with umask(0177); the previous umask comes back even when it throws. */
    public function withPrivateUmask(callable $callback): mixed
    {
        $previous = umask(0177);

        try {
            return $callback();
        } finally {
            umask($previous);
        }
    }

    /** An EMPTY file with mode 0600 inside the backup folder; returns its path. */
    public function privateFile(string $name): string
    {
        return $this->withPrivateUmask(function () use ($name) {
            $path = $this->directory().DIRECTORY_SEPARATOR.$name;

            if (@file_put_contents($path, '') === false) {
                throw new BackupFailed(BackupFailed::CANNOT_WRITE);
            }

            @chmod($path, 0600);

            return $path;
        });
    }

    /** Name of a temporary file, e.g. ".tmp-db-3f9a1c.cnf"; all of them are removed by cleanLeftovers(). */
    public function tempName(string $kind, string $extension): string
    {
        return '.tmp-'.$kind.'-'.bin2hex(random_bytes(6)).'.'.$extension;
    }

    /** Name of a finished backup: <APP_ENV>-<db|files>-YYYYmmdd-HHMMSS.<sql.gz|tar.gz>. */
    public function finalName(string $kind): string
    {
        return $this->slug().'-'.$kind.'-'.now()->format('Ymd-His').'.'.self::EXTENSIONS[$kind];
    }

    /** Move a finished temporary file to its final name (mode 0600). */
    public function finalize(string $temporary, string $finalName): string
    {
        $final = $this->directory().DIRECTORY_SEPARATOR.$finalName;

        if (! @rename($temporary, $final)) {
            throw new BackupFailed(BackupFailed::CANNOT_WRITE);
        }

        @chmod($final, 0600);

        return $final;
    }

    /**
     * Delete what an interrupted run (a killed process) left behind: the temporary files of this kind.
     * Finished backups and files of anybody else are never touched.
     */
    public function cleanLeftovers(string $kind): int
    {
        $deleted = 0;
        $pattern = '/^\.tmp-'.preg_quote($kind, '/').'-[a-f0-9]+\.(cnf|sql|sql\.gz|tar|tar\.gz)$/';

        foreach ($this->entries() as $file) {
            if (preg_match($pattern, $file) === 1 && @unlink($this->directory().DIRECTORY_SEPARATOR.$file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Keep the $keep newest finished backups of this kind and delete the rest. Only names that match
     * the exact pattern of this kind and this environment are considered. Never deletes all of them.
     *
     * @return list<string> names of the deleted files
     */
    public function prune(string $kind, int $keep): array
    {
        $deleted = [];

        foreach (array_slice($this->finished($kind), max(1, $keep)) as $name) {
            if (@unlink($this->directory().DIRECTORY_SEPARATOR.$name)) {
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    /** @return list<string> finished backups of this kind, the newest first */
    public function finished(string $kind): array
    {
        $pattern = '/^'.preg_quote($this->slug(), '/').'-'.preg_quote($kind, '/').'-\d{8}-\d{6}\.'.preg_quote(self::EXTENSIONS[$kind], '/').'$/';
        $names = array_values(array_filter($this->entries(), fn (string $file) => preg_match($pattern, $file) === 1));
        rsort($names, SORT_STRING);

        return $names;
    }

    public static function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1, ',', '.').' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 1, ',', '.').' KB',
            default => $bytes.' B',
        };
    }

    /** @return list<string> */
    private function entries(): array
    {
        $entries = @scandir($this->directory());

        return array_values(array_filter($entries === false ? [] : $entries, fn (string $f) => ! in_array($f, ['.', '..'], true)));
    }

    private function slug(): string
    {
        return preg_replace('/[^a-z0-9_]/i', '', $this->env) ?: 'app';
    }

    private function isInside(string $path, string $base): bool
    {
        $path = $this->absolute($path);
        $base = $this->absolute($base);

        return $path === $base || str_starts_with($path.'/', $base.'/');
    }

    /** Absolute path with forward slashes; the part that does not exist yet is resolved lexically. */
    private function absolute(string $path): string
    {
        $path = str_replace(chr(92), '/', $path);
        $existing = $path;
        $rest = [];

        while ($existing !== '' && $existing !== '/' && realpath($existing) === false) {
            array_unshift($rest, basename($existing));
            $parent = dirname($existing);

            if ($parent === $existing) {
                break;
            }

            $existing = $parent;
        }

        $real = realpath($existing);
        $resolved = ($real === false ? $existing : str_replace(chr(92), '/', $real)).($rest ? '/'.implode('/', $rest) : '');
        $resolved = rtrim($resolved, '/');

        return chr(92) === DIRECTORY_SEPARATOR ? strtolower($resolved) : $resolved;
    }
}
