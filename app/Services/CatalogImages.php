<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Order of operations for an uploaded catalog image, shared by car models and equipment items:
 *
 *  1. the new file is stored under a random name (hashName(): the extension comes from the
 *     DETECTED mime type, never from the client's file name);
 *  2. the caller writes the database (it receives the image attributes to save);
 *  3. only AFTER that succeeds is the old file deleted.
 *
 * If the write fails the NEW file is removed and the old image stays.
 */
class CatalogImages
{
    /**
     * @param  callable(array<string, string|null>): mixed  $persist  gets ['image_path' => ...] or [] when the image is untouched
     */
    public function save(string $directory, ?UploadedFile $file, bool $remove, ?string $oldPath, callable $persist): mixed
    {
        $disk = Storage::disk('public');
        $newPath = null;
        $attributes = [];

        try {
            if ($file !== null) {
                $stored = $file->store($directory, 'public');

                if ($stored === false) {
                    throw new RuntimeException('The uploaded image could not be stored.');
                }

                $newPath = $stored;
                $attributes = ['image_path' => $newPath];
            } elseif ($remove) {
                $attributes = ['image_path' => null];
            }

            $result = $persist($attributes);
        } catch (Throwable $e) {
            if ($newPath !== null) {
                $disk->delete($newPath);
            }

            throw $e;
        }

        if ($oldPath !== null && $attributes !== [] && $oldPath !== ($attributes['image_path'] ?? null)) {
            $disk->delete($oldPath);
        }

        return $result;
    }

    /**
     * Delete a file after the row it belonged to is gone.
     */
    public function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }
}
