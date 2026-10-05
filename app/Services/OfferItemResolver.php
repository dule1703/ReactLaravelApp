<?php

namespace App\Services;

use App\Enums\EquipmentAvailability;
use App\Enums\OptionSelection;
use App\Models\TrimEquipment;
use App\Models\Version;
use App\Support\OfferCalculator;

/**
 * Turns the client's CHOICE into the snapshot of the offer items, read from the catalog now (call
 * it inside the transaction that saves the offer).
 *
 * Input is untrusted JSON: per item only `version_id`, `quantity` and `option_ids` (ids of
 * equipment items) are read; names and prices are never accepted, any other key is ignored. What
 * may be offered is decided only by Version::available() and Version::offerableExtras() (which
 * uses EquipmentItem::scopeOfferable()); this class adds no second rule about activity. Standard
 * equipment is already in the price and cannot be chosen, and so is the standard item of a
 * `single` group (the client simply sends nothing for the default).
 *
 * An extra of a `single` group is a SURCHARGE (is_surcharge): the calculation adds it to the line.
 * An extra of a `multiple` group is a plain extra that keeps the group name.
 *
 * Wrong shapes and types are an OfferItemsException (422), never a TypeError.
 *
 * @phpstan-type Resolved list<array{item: array<string, mixed>, options: list<array<string, mixed>>}>
 */
class OfferItemResolver
{
    /**
     * @return Resolved item attributes and option attributes, positions are 1-based
     *
     * @throws OfferItemsException
     */
    public function resolve(mixed $items): array
    {
        $errors = [];
        $choices = $this->parse($items, $errors);

        if ($errors !== []) {
            throw OfferItemsException::withMessages($errors);
        }

        $versions = Version::available()
            ->with(['trim.carModel', 'engine', 'transmission'])
            ->whereIn('id', array_unique(array_column($choices, 'version_id')))
            ->get()
            ->keyBy('id');

        $resolved = [];

        foreach ($choices as $index => $choice) {
            $version = $versions->get($choice['version_id']);

            if ($version === null) {
                $errors["items.$index.version_id"][] = __('offer.item.version_unavailable');

                continue;
            }

            $options = $this->resolveOptions($version, $choice['option_ids'], $index, $errors);

            $resolved[] = [
                'item' => [
                    'position' => $index + 1,
                    'quantity' => $choice['quantity'],
                    'car_model_name' => $version->trim->carModel->name,
                    'trim_name' => $version->trim->name,
                    'engine_name' => $version->engine->name,
                    'fuel_type' => $version->engine->fuel_type->value,
                    'power_kw' => $version->engine->power_kw,
                    'transmission_name' => $version->transmission->name,
                    'drive' => $version->transmission->drive->value,
                    'version_price_cents' => $version->base_price_cents,
                ],
                'options' => $options,
            ];
        }

        if ($errors !== []) {
            throw OfferItemsException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * @param  list<int>  $optionIds
     * @param  array<string, list<string>>  $errors
     * @return list<array<string, mixed>>
     */
    private function resolveOptions(Version $version, array $optionIds, int $index, array &$errors): array
    {
        if ($optionIds === []) {
            return [];
        }

        $extras = $version->offerableExtras()->whereIn('equipment_items.id', $optionIds)->get()->keyBy('id');
        $standardIds = null;
        $seen = [];
        $singleGroups = [];
        $chosen = [];

        foreach ($optionIds as $position => $id) {
            $key = "items.$index.option_ids.$position";

            if (isset($seen[$id])) {
                $errors[$key][] = __('offer.item.option_duplicate');

                continue;
            }

            $seen[$id] = true;
            $extra = $extras->get($id);

            if ($extra === null) {
                $standardIds ??= TrimEquipment::query()
                    ->where('trim_id', $version->trim_id)
                    ->where('availability', EquipmentAvailability::Standard->value)
                    ->whereIn('equipment_item_id', $optionIds)
                    ->pluck('equipment_item_id')
                    ->all();

                $errors[$key][] = in_array($id, $standardIds, true)
                    ? __('offer.item.option_standard')
                    : __('offer.item.option_unavailable');

                continue;
            }

            if ($extra->group?->selection === OptionSelection::Single) {
                if (isset($singleGroups[$extra->group_id])) {
                    $errors[$key][] = __('offer.item.option_group_duplicate');

                    continue;
                }

                $singleGroups[$extra->group_id] = true;
            }

            $chosen[$id] = true;
        }

        $options = [];

        // In the display order of the catalog (category, sort order), not the client's order.
        foreach ($extras as $id => $extra) {
            if (! isset($chosen[$id])) {
                continue;
            }

            $options[] = [
                'position' => count($options) + 1,
                'name' => $extra->name,
                'category' => $extra->category->value,
                'group_name' => $extra->group?->name,
                'is_surcharge' => $extra->group?->selection === OptionSelection::Single,
                'price_cents' => (int) $extra->extra_price_cents,
            ];
        }

        return $options;
    }

    /**
     * Checks the shape of the client's JSON; fills $errors and returns the cleaned choices.
     *
     * @param  array<string, list<string>>  $errors
     * @return list<array{version_id: int, quantity: int, option_ids: list<int>}>
     */
    private function parse(mixed $items, array &$errors): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            $errors['items'][] = __('offer.item.invalid');

            return [];
        }

        if (count($items) > OfferCalculator::MAX_ITEMS) {
            $errors['items'][] = __('offer.item.too_many_items', ['max' => OfferCalculator::MAX_ITEMS]);

            return [];
        }

        $choices = [];
        $totalOptions = 0;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $errors["items.$index"][] = __('offer.item.invalid');

                continue;
            }

            $versionId = $item['version_id'] ?? null;

            if (! is_int($versionId) || $versionId < 1) {
                $errors["items.$index.version_id"][] = __('offer.item.version_invalid');
            }

            $quantity = $item['quantity'] ?? null;

            if (! is_int($quantity) || $quantity < 1 || $quantity > OfferCalculator::MAX_QUANTITY) {
                $errors["items.$index.quantity"][] = __('offer.item.quantity_invalid', ['max' => OfferCalculator::MAX_QUANTITY]);
            }

            $optionIds = $item['option_ids'] ?? null;

            if (! is_array($optionIds) || ! array_is_list($optionIds)
                || count($optionIds) > OfferCalculator::MAX_OPTIONS
                || array_filter($optionIds, fn ($id) => ! is_int($id) || $id < 1) !== []) {
                $errors["items.$index.option_ids"][] = __('offer.item.options_invalid', ['max' => OfferCalculator::MAX_OPTIONS]);

                continue;
            }

            $totalOptions += count($optionIds);
            $choices[$index] = ['version_id' => $versionId, 'quantity' => $quantity, 'option_ids' => $optionIds];
        }

        if ($totalOptions > OfferCalculator::MAX_TOTAL_OPTIONS) {
            $errors['items'][] = __('offer.item.too_many_options', ['max' => OfferCalculator::MAX_TOTAL_OPTIONS]);
        }

        return $errors === [] ? array_values($choices) : [];
    }
}
