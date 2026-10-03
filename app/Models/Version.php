<?php

namespace App\Models;

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
