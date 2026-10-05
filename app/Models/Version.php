<?php

namespace App\Models;

use App\Enums\EquipmentAvailability;
use App\Models\Concerns\LogsActivity;
use Database\Factories\VersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * A sellable combination: trim + engine + transmission with a base price. The price is an
 * integer in cents, NET of VAT; a price change is logged with old and new value (not sensitive).
 */
class Version extends Model
{
    /** @use HasFactory<VersionFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['trim_id', 'engine_id', 'transmission_id', 'base_price_cents', 'is_active'];

    protected function casts(): array
    {
        return [
            'base_price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The single place that decides what can be offered: the version itself, its trim, the
     * trim's car model, the engine and the transmission must all be active.
     *
     * @param  Builder<Version>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('versions.is_active', true)
            ->whereHas('trim', fn (Builder $trim) => $trim
                ->where('is_active', true)
                ->whereHas('carModel', fn (Builder $model) => $model->where('is_active', true)))
            ->whereHas('engine', fn (Builder $engine) => $engine->where('is_active', true))
            ->whereHas('transmission', fn (Builder $transmission) => $transmission->where('is_active', true));
    }

    /**
     * The ONLY query of extras a client may choose on this version: optional (priced) rows of its
     * trim whose item passes EquipmentItem::scopeOfferable(), in display order, with the net
     * price as `extra_price_cents` (for a `single` group it is the surcharge). Standard
     * equipment is not here (already in the price). Whether the version itself can be offered
     * is Version::available(), checked by the caller.
     *
     * @return Builder<EquipmentItem>
     */
    public function offerableExtras(): Builder
    {
        return EquipmentItem::query()
            ->offerable()
            ->join('trim_equipment', 'trim_equipment.equipment_item_id', '=', 'equipment_items.id')
            ->where('trim_equipment.trim_id', $this->trim_id)
            ->where('trim_equipment.availability', EquipmentAvailability::Optional->value)
            ->select('equipment_items.*', 'trim_equipment.price_cents as extra_price_cents')
            ->with('group')
            ->ordered();
    }

    /**
     * Standard (priced into the version) equipment of the trim that can still be shown: the item
     * passes EquipmentItem::scopeOfferable(). For display only; it is never chosen or priced.
     *
     * @return Builder<EquipmentItem>
     */
    public function standardEquipment(): Builder
    {
        return EquipmentItem::query()
            ->offerable()
            ->join('trim_equipment', 'trim_equipment.equipment_item_id', '=', 'equipment_items.id')
            ->where('trim_equipment.trim_id', $this->trim_id)
            ->where('trim_equipment.availability', EquipmentAvailability::Standard->value)
            ->select('equipment_items.*')
            ->with('group')
            ->ordered();
    }

    /**
     * @return BelongsTo<Trim, $this>
     */
    public function trim(): BelongsTo
    {
        return $this->belongsTo(Trim::class);
    }

    /**
     * @return BelongsTo<Engine, $this>
     */
    public function engine(): BelongsTo
    {
        return $this->belongsTo(Engine::class);
    }

    /**
     * @return BelongsTo<Transmission, $this>
     */
    public function transmission(): BelongsTo
    {
        return $this->belongsTo(Transmission::class);
    }

    /**
     * The car model, derived through the trim.
     *
     * @return HasOneThrough<CarModel, Trim, $this>
     */
    public function carModel(): HasOneThrough
    {
        return $this->hasOneThrough(CarModel::class, Trim::class, 'id', 'id', 'trim_id', 'car_model_id');
    }
}
