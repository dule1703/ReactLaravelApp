<?php

namespace App\Models;

use App\Enums\EquipmentAvailability;
use App\Models\Concerns\LogsActivity;
use Database\Factories\TrimEquipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use InvalidArgumentException;

/**
 * One equipment item on one trim. A pivot with its own id so every change (price,
 * availability) goes through model events into the activity log.
 *
 * Price rule: standard => price_cents is null; optional => price_cents >= 0 (0 is a free
 * option). Enforced on every save here; change rows only through this model, never through
 * the query builder or attach()/sync(), which would skip the rule and the log.
 */
class TrimEquipment extends Pivot
{
    /** @use HasFactory<TrimEquipmentFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'trim_equipment';

    public $incrementing = true;

    public $timestamps = true;

    /**
     * @var list<string>
     */
    protected $fillable = ['trim_id', 'equipment_item_id', 'availability', 'price_cents'];

    protected function casts(): array
    {
        return [
            'availability' => EquipmentAvailability::class,
            'price_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $row) {
            $price = $row->price_cents;

            if ($row->availability === EquipmentAvailability::Standard && $price !== null) {
                throw new InvalidArgumentException('Standard equipment must not have a price.');
            }

            if ($row->availability === EquipmentAvailability::Optional && ($price === null || $price < 0)) {
                throw new InvalidArgumentException('Optional equipment needs a price of 0 or more.');
            }
        });
    }

    /**
     * @return BelongsTo<Trim, $this>
     */
    public function trim(): BelongsTo
    {
        return $this->belongsTo(Trim::class);
    }

    /**
     * @return BelongsTo<EquipmentItem, $this>
     */
    public function equipmentItem(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class);
    }
}
