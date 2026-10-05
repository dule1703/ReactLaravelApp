<?php

namespace App\Models;

use App\Enums\EquipmentCategory;
use App\Enums\OptionSelection;
use App\Models\Concerns\LogsActivity;
use Database\Factories\EquipmentItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * An equipment item shared by all car models; what a trim offers (and at what price) is in
 * TrimEquipment. An item without a group is an independent extra; an item of an OptionGroup is
 * one choice of a "one of several" set (colors, wheels, ...).
 *
 * Local rules on every save: the category must match the group's category, and a color swatch
 * (#RRGGBB) is allowed only for items of a group that uses swatches.
 */
class EquipmentItem extends Model
{
    /** @use HasFactory<EquipmentItemFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'category', 'group_id', 'image_path', 'swatch_hex', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'category' => EquipmentCategory::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $group = $item->group_id !== null ? OptionGroup::find($item->group_id) : null;

            if ($group !== null && $item->category !== $group->category) {
                throw new InvalidArgumentException('The category of an item must match the category of its option group.');
            }

            if ($item->swatch_hex !== null) {
                if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $item->swatch_hex)) {
                    throw new InvalidArgumentException('The swatch must be a color in the form #RRGGBB.');
                }

                if ($group === null || ! $group->uses_swatch) {
                    throw new InvalidArgumentException('A swatch is allowed only for items of a group that uses swatches.');
                }
            }
        });
    }

    /**
     * Public URL of the uploaded image (relative to the current host), or null.
     */
    public function imageUrl(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }

    /**
     * @return BelongsTo<OptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'group_id');
    }

    /**
     * @param  Builder<EquipmentItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('equipment_items.is_active', true);
    }

    /**
     * The ONLY place that decides which equipment can be offered (new offers): the item is active
     * and it either has no group or its group is active. A deactivated group takes its items out
     * of the configurator; existing offers are snapshots and are not affected.
     *
     * @param  Builder<EquipmentItem>  $query
     */
    public function scopeOfferable(Builder $query): void
    {
        $query->where('equipment_items.is_active', true)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('equipment_items.group_id')
                ->orWhereHas('group', fn (Builder $group) => $group->where('is_active', true)));
    }

    /**
     * On how many trims this item is the STANDARD item of a single-choice group. Such an item
     * cannot be deactivated or moved out of its group directly: the group would be left without
     * a standard item on those trims (replace it in the equipment matrix first).
     */
    public function standardLinesInSingleGroup(): int
    {
        if ($this->group_id === null || $this->group?->selection !== OptionSelection::Single) {
            return 0;
        }

        return $this->trimEquipment()->where('availability', 'standard')->count();
    }

    /**
     * Category in the order of the EquipmentCategory cases (not alphabetical), then sort_order.
     *
     * @param  Builder<EquipmentItem>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $cases = collect(EquipmentCategory::cases())
            ->map(fn (EquipmentCategory $category, int $position) => "when '{$category->value}' then {$position}")
            ->implode(' ');

        $query->orderByRaw('case equipment_items.category '.$cases.' else '.count(EquipmentCategory::cases()).' end')
            ->orderBy('equipment_items.sort_order')
            ->orderBy('equipment_items.id');
    }

    /**
     * @return HasMany<TrimEquipment, $this>
     */
    public function trimEquipment(): HasMany
    {
        return $this->hasMany(TrimEquipment::class);
    }

    /**
     * @return BelongsToMany<Trim, $this, TrimEquipment>
     */
    public function trims(): BelongsToMany
    {
        return $this->belongsToMany(Trim::class, 'trim_equipment')
            ->using(TrimEquipment::class)
            ->withPivot(['id', 'availability', 'price_cents'])
            ->withTimestamps();
    }
}
