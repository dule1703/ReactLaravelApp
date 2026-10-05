<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\User;
use App\Support\ClientSnapshot;
use App\Support\OfferClientRules;
use App\Support\VatRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates an offer header: the VAT rate and the client details are SNAPSHOTS taken now, so later
 * changes of the settings or of the profile never touch the offer. The date is set by the server
 * (app timezone) and the number takes its year from the same value. Who may create an offer for
 * whom is decided by the Policy (4.7), not here.
 *
 * Everything runs in one transaction with a retry, so a failure leaves no offer and no gap in the
 * numbers. Phase 4.5 adds the items to this transaction (an extra `array $items = []` parameter).
 */
class OfferCreator
{
    public function __construct(private readonly OfferNumber $numbers) {}

    /**
     * @throws InvalidArgumentException when $client is not a client (an offer always belongs to one)
     * @throws ValidationException when the profile lacks required details (keys are profile fields)
     * @throws \UnexpectedValueException when the stored VAT rate is invalid
     */
    public function create(User $client, ?string $note = null): Offer
    {
        if (! $client->isClient()) {
            throw new InvalidArgumentException('An offer can only be created for a client.');
        }

        return DB::transaction(function () use ($client, $note) {
            $profile = $client->profile();
            $missing = OfferClientRules::missing($profile);

            if ($missing !== []) {
                throw ValidationException::withMessages(array_combine(
                    $missing,
                    array_map(fn (string $field) => [__('offer.profile.'.$field)], $missing),
                ));
            }

            $date = now()->startOfDay();
            $note = $note === null ? null : trim($note);

            $offer = (new Offer)->fill([
                'offer_date' => $date->toDateString(),
                'vat_rate_bp' => VatRate::current(),
                'note' => $note === '' ? null : $note,
                ...ClientSnapshot::from($profile),
            ]);
            $offer->user_id = $client->id;

            $this->numbers->assign($offer, $date->year);
            $offer->save();

            return $offer;
        }, 3);
    }
}
