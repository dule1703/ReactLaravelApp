<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\TrimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Equipment package ("trim") of a car model, e.g. Ambition or Style.
 */
class Trim extends Model
{
    /** @use HasFactory<TrimFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['car_model_id', 'name', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CarModel, $this>
     */
    public function carModel(): BelongsTo
    {
        return $this->belongsTo(CarModel::class);
    }

    /**
     * @return HasMany<TrimEquipment, $this>
     */
    public function trimEquipment(): HasMany
    {
        return $this->hasMany(TrimEquipment::class);
    }

    /**
     * Equipment offered on this trim, with the pivot fields availability and price_cents.
     * Read-only access: write through TrimEquipment.
     *
     * @return BelongsToMany<EquipmentItem, $this, TrimEquipment>
     */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(EquipmentItem::class, 'trim_equipment')
            ->using(TrimEquipment::class)
            ->withPivot(['id', 'availability', 'price_cents'])
            ->withTimestamps();
    }

    /**
     * Only equipment that can be offered (EquipmentItem::scopeOfferable), in category (enum)
     * order, then sort_order.
     *
     * @return BelongsToMany<EquipmentItem, $this, TrimEquipment>
     */
    public function activeEquipment(): BelongsToMany
    {
        return $this->equipment()->offerable()->ordered();
    }

    /**
     * @return HasMany<Version, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class);
    }
}
