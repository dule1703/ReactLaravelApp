<?php

namespace App\Models;

use App\Enums\EquipmentAvailability;
use App\Enums\OptionSelection;
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
 * option). For an item of an option group the optional price is a SURCHARGE over the group's
 * standard item (the difference), not a total price. Enforced on every save here; change rows
 * only through this model, never through the query builder or attach()/sync(), which would
 * skip the rule and the log.
 *
 * Rows of option group items are NOT changed one by one: the matrix service (3.11) changes the
 * rows of a group on a trim in one transaction and checks the final state with OptionGroupRule.
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
            'trim_id' => 'integer',
            'equipment_item_id' => 'integer',
        ];
    }

    /**
     * The log entry names the trim and the item, not their ids: "Essence · Climatronic".
     */
    public function activityLabel(): string
    {
        return $this->trim?->name.' · '.$this->equipmentItem?->name;
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

            // "At most one standard item per single-choice group and trim". "Exactly one" cannot
            // be enforced here (swapping the standard item passes through a state without one),
            // so it is checked on the final state by OptionGroupRule in the file validation and
            // in the matrix service. Demoting a standard item is never blocked.
            if ($row->availability === EquipmentAvailability::Standard) {
                $group = EquipmentItem::find($row->equipment_item_id)?->group;

                if ($group !== null && $group->selection === OptionSelection::Single) {
                    $otherStandard = static::query()
                        ->where('trim_id', $row->trim_id)
                        ->where('availability', EquipmentAvailability::Standard->value)
                        ->when($row->exists, fn ($query) => $query->whereKeyNot($row->getKey()))
                        ->whereHas('equipmentItem', fn ($query) => $query->where('group_id', $group->id))
                        ->exists();

                    if ($otherStandard) {
                        throw new InvalidArgumentException('A single-choice group already has a standard item on this trim; demote it first.');
                    }
                }
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
