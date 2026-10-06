<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

/**
 * A price offer: a SNAPSHOT of names, prices, the VAT rate and a few client details taken when
 * it was created (phase 4). It never references catalog rows, so changing, deactivating or
 * deleting the catalog cannot change it. Amounts are integers in cents. The owner is a
 * registered client (D1); a client with offers cannot be deleted. The JMBG is never copied.
 *
 * The number (year, seq, number) and the owner are assigned by the offer service (4.2/4.3), so
 * they are not mass-assignable. Neither is the status (`withdrawn_at`, `deleted_at`): only
 * OfferStatus sets it. An offer is deleted softly (the row and its number stay); every query on
 * Offer hides deleted ones by default, so anything that must count them says withTrashed().
 */
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

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
            'withdrawn_at' => 'datetime',
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

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    /**
     * A change of the status only is "offer.withdrawn" / "offer.withdrawal_reverted", not a generic
     * "offer.updated" (the log would get two entries for one action).
     *
     * @param  array<string, mixed>  $changes
     */
    public function activityAction(string $event, array $changes): string
    {
        if ($event === 'updated' && array_keys($changes) === ['withdrawn_at']) {
            return $this->withdrawn_at === null ? 'offer.withdrawal_reverted' : 'offer.withdrawn';
        }

        return 'offer.'.$event;
    }

    /**
     * A soft delete and a restore are logged by OfferStatus ("offer.deleted" / "offer.restored", no
     * changes: a delete entry of the trait would copy the client data). Muting them here avoids a
     * second, generic entry for the same action. A real (force) delete is still logged by the trait.
     */
    public function activityMuted(string $event): bool
    {
        return match ($event) {
            'deleted' => ! $this->isForceDeleting(),
            'updated' => array_keys(Arr::except($this->getChanges(), ['updated_at'])) === ['deleted_at'],
            default => false,
        };
    }

    public function activityLabel(): string
    {
        return __('Offer').' '.$this->number;
    }
}
