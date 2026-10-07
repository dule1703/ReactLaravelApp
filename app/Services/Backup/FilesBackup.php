<?php

namespace App\Services\Backup;

use FilesystemIterator;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * A .tar.gz of the files that live only on the server (7.2): the uploaded images (the public disk,
 * storage/app/public, stored under "public/") and the private real catalog file (CATALOG_REAL_PATH, stored
 * as "private/real_catalog.php"). shared/.env is NOT part of it (it holds secrets: the owner keeps it).
 *
 * Uses PharData (the phar and zlib extensions), no shell. Symbolic links are skipped, ".gitignore"
 * placeholders are not part of the backup. Without any file there is nothing to back up (null).
 */
class FilesBackup
{
    public function __construct(private readonly BackupStorage $storage) {}

    /**
     * @return array{name: string, path: string, bytes: int, files: int, deleted: list<string>}|null
     *
     * @throws BackupFailed
     */
    public function run(): ?array
    {
        if (! class_exists(PharData::class) || ! function_exists('gzopen')) {
            throw new BackupFailed(BackupFailed::ARCHIVE_FAILED, 'phar or zlib is missing');
        }

        $this->storage->directory();
        $this->storage->cleanLeftovers('files');

        $sources = $this->sources();

        if ($sources === []) {
            return null;
        }

        $tar = $this->storage->directory().DIRECTORY_SEPARATOR.$this->storage->tempName('files', 'tar');
        $tarGz = $tar.'.gz';
        $kept = false;

        try {
            $final = $this->storage->withPrivateUmask(function () use ($tar, $sources) {
                $archive = new PharData($tar);

                foreach ($sources as $name => $path) {
                    $archive->addFile($path, $name);
                }

                $archive->compress(Phar::GZ);
                unset($archive); // release the file before it is deleted (Windows)

                return $sources;
            });

            // Open what was written and make sure it holds at least one entry BEFORE it is kept and older ones are pruned.
            if (! is_file($tarGz) || filesize($tarGz) === 0 || $this->entryCount($tarGz) < 1) {
                throw new BackupFailed(BackupFailed::ARCHIVE_FAILED);
            }

            $name = $this->storage->finalName('files');
            $path = $this->storage->finalize($tarGz, $name);
            $kept = true;

            return [
                'name' => $name,
                'path' => $path,
                'bytes' => (int) filesize($path),
                'files' => count($final),
                'deleted' => $this->storage->prune('files', (int) config('backup.files_keep')),
            ];
        } catch (BackupFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BackupFailed(BackupFailed::ARCHIVE_FAILED);
        } finally {
            foreach ([$tar, $kept ? null : $tarGz] as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /** Number of entries in the finished archive; 0 when it cannot be opened. */
    protected function entryCount(string $archive): int
    {
        try {
            $tar = new PharData($archive);
            $count = count($tar);
            unset($tar);

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string, string> name inside the archive => absolute path */
    private function sources(): array
    {
        $sources = [];
        $root = (string) config('filesystems.disks.public.root');

        if ($root !== '' && is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isLink() || ! $file->isFile() || $file->getFilename() === '.gitignore') {
                    continue;
                }

                $relative = str_replace(chr(92), '/', substr($file->getPathname(), strlen(rtrim($root, '/'.chr(92))) + 1));
                $sources['public/'.$relative] = $file->getPathname();
            }
        }

        $catalog = (string) config('catalog.real_catalog_path');
        $frame = database_path('seeders/data/real_catalog.php'); // the empty frame in the repository is no data

        if ($catalog !== '' && is_file($catalog) && realpath($catalog) !== realpath($frame)) {
            $sources['private/real_catalog.php'] = $catalog;
        }

        ksort($sources);

        return $sources;
    }
}
