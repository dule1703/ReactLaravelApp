<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\OfferItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * One configured vehicle of an offer: the snapshot of model, trim, engine, transmission and the
 * net version price, plus the number of vehicles. No reference to the catalog.
 */
class OfferItem extends Model
{
    /** @use HasFactory<OfferItemFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'position',
        'quantity',
        'car_model_name',
        'trim_name',
        'engine_name',
        'fuel_type',
        'power_kw',
        'transmission_name',
        'drive',
        'version_price_cents',
        'line_net_cents',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'power_kw' => 'integer',
            'version_price_cents' => 'integer',
            'line_net_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            if ($item->quantity < 1) {
                throw new InvalidArgumentException('An offer item needs at least one vehicle.');
            }

            if ($item->version_price_cents < 0 || ($item->line_net_cents !== null && $item->line_net_cents < 0)) {
                throw new InvalidArgumentException('Amounts of an offer item must not be negative.');
            }
        });
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return HasMany<OfferItemOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(OfferItemOption::class)->orderBy('position')->orderBy('id');
    }

    public function activityLabel(): string
    {
        return __('Offer').' '.$this->offer?->number.' · '.$this->car_model_name.' '.$this->trim_name;
    }
}
