<?php

namespace App\Services;

use App\Models\EquipmentItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Saves and removes the image (and the color swatch) of an equipment item through the model, so
 * the change reaches the activity log. The screen that uses it arrives in 3.10.
 */
class EquipmentItemImages
{
    public function __construct(private readonly CatalogImages $images) {}

    /**
     * Replace the image with $file, or remove it with $remove; nothing changes if both are
     * empty. The old file is deleted only after the database write; a failed write removes the
     * new file and keeps the old image.
     */
    public function save(EquipmentItem $item, ?UploadedFile $file = null, bool $remove = false): EquipmentItem
    {
        return $this->images->save(
            'catalog/equipment',
            $file,
            $remove,
            $item->image_path,
            function (array $attributes) use ($item) {
                if ($attributes !== []) {
                    DB::transaction(fn () => $item->update($attributes));
                }

                return $item;
            },
        );
    }

    /**
     * Delete the item and, after the delete committed, its image file.
     */
    public function delete(EquipmentItem $item): void
    {
        $path = $item->image_path;

        DB::transaction(fn () => $item->delete());

        $this->images->forget($path);
    }
}
