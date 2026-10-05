<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price offer: a SNAPSHOT of names, prices, the VAT rate and a few client details taken when
 * it was created (phase 4). It never references catalog rows, so changing, deactivating or
 * deleting the catalog cannot change it. Amounts are integers in cents. The owner is a
 * registered client (D1); a client with offers cannot be deleted. The JMBG is never copied.
 *
 * The number (year, seq, number) and the owner are assigned by the offer service (4.2/4.3), so
 * they are not mass-assignable.
 */
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory, LogsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'offer_date',
        'vat_rate_bp',
        'note',
        'client_type',
        'client_name',
        'client_pib',
        'client_address',
        'client_postal_code',
        'client_city',
        'client_country',
        'total_net_cents',
        'vat_cents',
        'total_gross_cents',
    ];

    protected function casts(): array
    {
        return [
            'offer_date' => 'date',
            'year' => 'integer',
            'seq' => 'integer',
            'vat_rate_bp' => 'integer',
            'total_net_cents' => 'integer',
            'vat_cents' => 'integer',
            'total_gross_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OfferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('position')->orderBy('id');
    }

    public function activityLabel(): string
    {
        return __('Offer').' '.$this->number;
    }
}
