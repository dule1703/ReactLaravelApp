<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\User;
use App\Support\ClientSnapshot;
use App\Support\OfferCalculationException;
use App\Support\OfferCalculator;
use App\Support\OfferClientRules;
use App\Support\VatRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates an offer: the VAT rate and the client details are SNAPSHOTS taken now, so later changes
 * of the settings, the profile or the catalog never touch the offer. The date is set by the server
 * (app timezone) and the number takes its year from the same value. Who may create an offer for
 * whom is decided by the Policy (4.7), not here.
 *
 * Everything runs in one transaction with a retry, in this order: rate -> items resolved from the
 * catalog -> calculation -> comparison with the totals the client saw -> number -> rows. A failure
 * at any step leaves no offer and no gap in the numbers. The server always writes ONLY its own
 * amounts; if `$expected` (expected_total_net_cents, expected_total_gross_cents) differs from them,
 * OfferTotalMismatchException carries the server's amounts and nothing is written.
 *
 * Items and options are written without a log entry each (the models are muted for `created`),
 * with ONE summary entry `offer.items_created` instead. Updates and deletes stay logged (4.6).
 */
class OfferCreator
{
    public function __construct(
        private readonly OfferNumber $numbers,
        private readonly OfferItemResolver $resolver,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param  mixed  $items  the client's choice: [{version_id, quantity, option_ids}], see OfferItemResolver
     * @param  array<string, mixed>|null  $expected
     *
     * @throws InvalidArgumentException when $client is not a client (an offer always belongs to one)
     * @throws ValidationException when the profile lacks required details (keys are profile fields)
     * @throws OfferItemsException when the choice of items is not acceptable
     * @throws OfferTotalMismatchException when the totals differ from the ones the client saw
     * @throws \UnexpectedValueException when the stored VAT rate is invalid
     */
    public function create(User $client, ?string $note = null, mixed $items = [], ?array $expected = null): Offer
    {
        if (! $client->isClient()) {
            throw new InvalidArgumentException('An offer can only be created for a client.');
        }

        return DB::transaction(function () use ($client, $note, $items, $expected) {
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
            $rate = VatRate::current();

            $resolved = $this->resolver->resolve($items);
            $totals = $this->calculate($resolved, $rate);

            if ($expected !== null
                && (($expected['expected_total_net_cents'] ?? null) !== $totals['total_net_cents']
                    || ($expected['expected_total_gross_cents'] ?? null) !== $totals['total_gross_cents'])) {
                throw new OfferTotalMismatchException(
                    $totals['total_net_cents'],
                    $totals['vat_cents'],
                    $totals['total_gross_cents'],
                    $rate,
                );
            }

            $offer = (new Offer)->fill([
                'offer_date' => $date->toDateString(),
                'vat_rate_bp' => $rate,
                'note' => $note === '' ? null : $note,
                'total_net_cents' => $totals['total_net_cents'],
                'vat_cents' => $totals['vat_cents'],
                'total_gross_cents' => $totals['total_gross_cents'],
                ...ClientSnapshot::from($profile),
            ]);
            $offer->user_id = $client->id;

            $this->numbers->assign($offer, $date->year);
            $offer->save();

            $optionCount = $this->writeItems($offer, $resolved, $totals['items']);

            if ($resolved !== []) {
                $this->logger->log(
                    'offer.items_created',
                    $offer,
                    description: __('offer.items_summary', ['items' => count($resolved), 'options' => $optionCount]),
                    changes: ['items_count' => count($resolved), 'options_count' => $optionCount],
                );
            }

            return $offer;
        }, 3);
    }

    /**
     * @param  list<array{item: array<string, mixed>, options: list<array<string, mixed>>}>  $resolved
     * @return array{items: list<array{line_net_cents: int}>, total_net_cents: int, vat_cents: int, total_gross_cents: int}
     */
    private function calculate(array $resolved, int $rate): array
    {
        $input = array_map(fn (array $row) => [
            'version_price_cents' => $row['item']['version_price_cents'],
            'quantity' => $row['item']['quantity'],
            'options' => array_map(fn (array $option) => ['price_cents' => $option['price_cents']], $row['options']),
        ], $resolved);

        try {
            return OfferCalculator::calculate($input, $rate);
        } catch (OfferCalculationException) {
            // Input comes from the catalog, so the only way here is an amount over the limits.
            throw OfferItemsException::withMessages(['items' => [__('offer.item.total_too_large')]]);
        }
    }

    /**
     * @param  list<array{item: array<string, mixed>, options: list<array<string, mixed>>}>  $resolved
     * @param  list<array{line_net_cents: int}>  $lines
     * @return int the number of options written
     */
    private function writeItems(Offer $offer, array $resolved, array $lines): int
    {
        $optionCount = 0;

        foreach ($resolved as $index => $row) {
            $item = $offer->items()->make($row['item']);
            $item->line_net_cents = $lines[$index]['line_net_cents'];
            $item->muteCreationLog()->save();

            foreach ($row['options'] as $attributes) {
                $item->options()->make($attributes)->muteCreationLog()->save();
                $optionCount++;
            }
        }

        return $optionCount;
    }
}
