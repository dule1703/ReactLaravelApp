<?php

namespace App\Models;

use App\Enums\EquipmentCategory;
use App\Enums\OptionSelection;
use App\Models\Concerns\LogsActivity;
use Database\Factories\OptionGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "one of several" choice such as body colors, wheels or upholstery (selection: single), or a
 * set of independent items shown together (multiple). All items of a group share its category.
 *
 * For a single group, every trim that offers the group has EXACTLY ONE standard item (no
 * price); the others are surcharges (price >= 0 = the DIFFERENCE to the standard item, not a
 * total price). See OptionGroupRule. Items of a group are changed only through the services that
 * check the final state, not one by one through the model.
 */
class OptionGroup extends Model
{
    /** @use HasFactory<OptionGroupFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'category', 'selection', 'uses_swatch', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'category' => EquipmentCategory::class,
            'selection' => OptionSelection::class,
            'uses_swatch' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EquipmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EquipmentItem::class, 'group_id');
    }
}
