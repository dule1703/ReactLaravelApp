<?php

namespace App\Support;

use App\Enums\EquipmentAvailability;
use App\Models\OptionGroup;
use App\Models\Trim;
use App\Models\TrimEquipment;

/**
 * The rule of a single-choice option group on ONE trim: if the trim offers the group at all
 * (at least one entry), exactly one item is standard (no price) and every other entry is a
 * surcharge with a price >= 0 (the DIFFERENCE to the standard item). A trim without any entry
 * of the group does not offer the group. Groups with several independent choices have no rule.
 *
 * Pure on purpose: the seed file validation and the matrix service (3.11) check the FINAL state
 * with it. The TrimEquipment hook only enforces "at most one standard", because swapping the
 * standard item passes through a state without one.
 */
class OptionGroupRule
{
    public const NO_STANDARD = 'no_standard';

    public const MANY_STANDARD = 'many_standard';

    public const STANDARD_WITH_PRICE = 'standard_with_price';

    public const BAD_SURCHARGE = 'bad_surcharge';

    /**
     * @param  list<array{item: string, availability: string, price: int|null}>  $entries  the entries of one group on one trim
     * @return list<array{code: string, items: list<string>}> problems; empty when the state is valid
     */
    public static function problems(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $problems = [];
        $standard = array_values(array_filter($entries, fn (array $entry) => $entry['availability'] === EquipmentAvailability::Standard->value));

        if ($standard === []) {
            $problems[] = ['code' => self::NO_STANDARD, 'items' => array_column($entries, 'item')];
        } elseif (count($standard) > 1) {
            $problems[] = ['code' => self::MANY_STANDARD, 'items' => array_column($standard, 'item')];
        }

        foreach ($entries as $entry) {
            if ($entry['availability'] === EquipmentAvailability::Standard->value) {
                if ($entry['price'] !== null) {
                    $problems[] = ['code' => self::STANDARD_WITH_PRICE, 'items' => [$entry['item']]];
                }
            } elseif (! is_int($entry['price']) || $entry['price'] < 0) {
                $problems[] = ['code' => self::BAD_SURCHARGE, 'items' => [$entry['item']]];
            }
        }

        return $problems;
    }

    /**
     * The same check on the database state of one group on one trim. $asSingle judges a group as
     * if it were single-choice (used before switching a group from multiple to single).
     *
     * @return list<array{code: string, items: list<string>}>
     */
    public static function problemsFor(OptionGroup $group, Trim $trim, bool $asSingle = false): array
    {
        if (! $asSingle && $group->selection->value !== 'single') {
            return [];
        }

        $entries = TrimEquipment::query()
            ->where('trim_id', $trim->id)
            ->whereHas('equipmentItem', fn ($query) => $query->where('group_id', $group->id))
            ->with('equipmentItem')
            ->get()
            ->map(fn (TrimEquipment $row) => [
                'item' => $row->equipmentItem->name,
                'availability' => $row->availability->value,
                'price' => $row->price_cents,
            ])
            ->all();

        return self::problems($entries);
    }
}
