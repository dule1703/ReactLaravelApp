<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\OfferItemOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Snapshot of one extra chosen for an offer item (name, category, net price in cents). For an
 * item of a single-choice option group `price_cents` is a SURCHARGE over the standard item:
 * `is_surcharge` and `group_name` keep that fact, and the calculation (4.4) ADDS it to the
 * version price, it never replaces it.
 */
class OfferItemOption extends Model
{
    /** @use HasFactory<OfferItemOptionFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['position', 'name', 'category', 'group_name', 'is_surcharge', 'price_cents'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_surcharge' => 'boolean',
            'price_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $option) {
            if ($option->price_cents < 0) {
                throw new InvalidArgumentException('The price of a chosen option must not be negative.');
            }
        });
    }

    /**
     * @return BelongsTo<OfferItem, $this>
     */
    public function offerItem(): BelongsTo
    {
        return $this->belongsTo(OfferItem::class);
    }

    public function activityLabel(): string
    {
        return __('Offer').' '.$this->offerItem?->offer?->number.' · '.$this->name;
    }
}
