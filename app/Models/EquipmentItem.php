<?php

namespace App\Models;

use App\Enums\EquipmentCategory;
use App\Models\Concerns\LogsActivity;
use Database\Factories\EquipmentItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An equipment item shared by all car models; what a trim offers (and at what price) is in
 * TrimEquipment.
 */
class EquipmentItem extends Model
{
    /** @use HasFactory<EquipmentItemFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'category', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'category' => EquipmentCategory::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<EquipmentItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('equipment_items.is_active', true);
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
