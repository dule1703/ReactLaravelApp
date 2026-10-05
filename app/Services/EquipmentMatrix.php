<?php

namespace App\Services;

use App\Enums\EquipmentAvailability;
use App\Enums\OptionSelection;
use App\Exceptions\MatrixConflictException;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Support\OptionGroupRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY place that changes equipment rows of a trim from the admin matrix (3.11).
 *
 * Every change runs in one transaction, through the TrimEquipment model (price hook + activity
 * log), on rows locked first. The browser sends the state it saw; if the database differs, the
 * change is refused with a conflict. Before commit the FINAL state of the option group is
 * checked with OptionGroupRule, so a single-choice group is either absent from the trim or has
 * exactly one standard item and surcharges (price >= 0, the difference to the standard item).
 *
 * "Not available" is the absence of a row, so it deletes the row. Offers (phase 4) keep
 * snapshots of names and prices, so deleting a row never changes an existing offer.
 */
class EquipmentMatrix
{
    public const NONE = 'none';

    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @param  'none'|'standard'|'optional'  $target
     * @param  array{availability: string, price_cents: ?int}  $expected  the state of the cell the browser saw
     * @param  array{availability: 'none'|'optional', price_cents: ?int}|null  $previous  what the previous standard item of a single-choice group becomes
     * @param  int|null  $expectedStandardItemId  the standard item of the group the browser saw
     */
    public function setCell(
        Trim $trim,
        EquipmentItem $item,
        string $target,
        ?int $priceCents,
        array $expected,
        ?array $previous = null,
        ?int $expectedStandardItemId = null,
    ): void {
        DB::transaction(function () use ($trim, $item, $target, $priceCents, $expected, $previous, $expectedStandardItemId) {
            $group = $item->group;
            $rows = $this->lockedRows($trim, $item);
            $current = $rows->get($item->id);

            if ($this->stateOf($current) !== $this->stateFrom($expected['availability'], $expected['price_cents'] ?? null)) {
                throw new MatrixConflictException;
            }

            $price = $target === EquipmentAvailability::Optional->value ? $priceCents : null;

            if ($this->stateOf($current) === $this->stateFrom($target, $price)) {
                return;
            }

            // A new or changed entry needs an offerable item; removing one is always allowed.
            if ($target !== self::NONE && ! EquipmentItem::query()->offerable()->whereKey($item->id)->exists()) {
                $this->refuse('availability', __('An inactive item (or an item of an inactive group) cannot be assigned.'));
            }

            $demoted = null;

            if ($group?->selection === OptionSelection::Single) {
                $demoted = $this->applySingleGroupRules($rows, $item, $current, $target, $previous, $expectedStandardItemId);
            }

            $this->write($trim, $item, $current, $target, $price);

            if ($group !== null) {
                $this->assertFinalState($group, $trim);
            }

            if ($demoted !== null) {
                $this->logSummary($trim, [$demoted, $item->name], 2);
            }
        });
    }

    /**
     * Remove every entry of an option group from the trim (the group is then not offered there).
     *
     * @param  list<array{equipment_item_id: int, availability: string, price_cents: ?int}>  $expected  the rows the browser saw
     */
    public function removeGroup(Trim $trim, OptionGroup $group, array $expected): void
    {
        DB::transaction(function () use ($trim, $group, $expected) {
            $rows = $this->groupRows($trim, $group);

            $seen = collect($expected)
                ->mapWithKeys(fn (array $row) => [(int) $row['equipment_item_id'] => $this->stateFrom($row['availability'], $row['price_cents'] ?? null)])
                ->sortKeys()->all();
            $now = $rows->mapWithKeys(fn (TrimEquipment $row) => [$row->equipment_item_id => $this->stateOf($row)])
                ->sortKeys()->all();

            if ($now === [] || $seen !== $now) {
                throw new MatrixConflictException;
            }

            $names = $rows->map(fn (TrimEquipment $row) => $row->equipmentItem->name)->all();

            foreach ($rows as $row) {
                $row->delete();
            }

            $this->assertFinalState($group, $trim);
            $this->logSummary($trim, $names, count($names), $group->name);
        });
    }

    /**
     * Rules that need the other rows of a single-choice group. Returns the name of the OTHER item
     * changed (the previous standard item when it is swapped), if any.
     *
     * @param  Collection<int, TrimEquipment>  $rows  the locked rows of the group on the trim
     */
    private function applySingleGroupRules(Collection $rows, EquipmentItem $item, ?TrimEquipment $current, string $target, ?array $previous, ?int $expectedStandardItemId): ?string
    {
        $others = $rows->reject(fn (TrimEquipment $row) => $row->equipment_item_id === $item->id);
        $otherStandard = $others->first(fn (TrimEquipment $row) => $row->availability === EquipmentAvailability::Standard);

        if ($target === EquipmentAvailability::Standard->value) {
            if ($expectedStandardItemId !== $otherStandard?->equipment_item_id) {
                throw new MatrixConflictException;
            }

            if ($otherStandard === null) {
                return null;
            }

            if ($previous === null) {
                $this->refuse('previous', __('Choose what the previous standard item becomes.'));
            }

            // Demote first: the model hook allows only one standard item per group and trim.
            if ($previous['availability'] === self::NONE) {
                $otherStandard->delete();
            } else {
                $otherStandard->update(['availability' => EquipmentAvailability::Optional, 'price_cents' => $previous['price_cents']]);
            }

            return $otherStandard->equipmentItem->name;
        }

        if ($current?->availability === EquipmentAvailability::Standard && ($target === EquipmentAvailability::Optional->value || $others->isNotEmpty())) {
            $this->refuse('availability', __('First set another item of the group as the standard one.'));
        }

        if ($target === EquipmentAvailability::Optional->value && $otherStandard === null) {
            $this->refuse('availability', __('First set the standard item of the group.'));
        }

        return null;
    }

    private function write(Trim $trim, EquipmentItem $item, ?TrimEquipment $current, string $target, ?int $price): void
    {
        if ($target === self::NONE) {
            $current?->delete();

            return;
        }

        $attributes = ['availability' => EquipmentAvailability::from($target), 'price_cents' => $price];

        if ($current === null) {
            TrimEquipment::create($attributes + ['trim_id' => $trim->id, 'equipment_item_id' => $item->id]);
        } else {
            $current->update($attributes);
        }
    }

    private function assertFinalState(OptionGroup $group, Trim $trim): void
    {
        if (OptionGroupRule::problemsFor($group, $trim) !== []) {
            $this->refuse('availability', __('The option group would not have exactly one standard item on this trim.'));
        }
    }

    /**
     * @return Collection<int, TrimEquipment> keyed by item id
     */
    private function lockedRows(Trim $trim, EquipmentItem $item): Collection
    {
        $query = TrimEquipment::query()->where('trim_id', $trim->id)->with('equipmentItem');

        $query = $item->group_id === null
            ? $query->where('equipment_item_id', $item->id)
            : $query->whereIn('equipment_item_id', EquipmentItem::query()->where('group_id', $item->group_id)->select('id'));

        return $query->lockForUpdate()->get()->keyBy('equipment_item_id');
    }

    /**
     * @return Collection<int, TrimEquipment>
     */
    private function groupRows(Trim $trim, OptionGroup $group): Collection
    {
        return TrimEquipment::query()
            ->where('trim_id', $trim->id)
            ->whereIn('equipment_item_id', EquipmentItem::query()->where('group_id', $group->id)->select('id'))
            ->with('equipmentItem')
            ->lockForUpdate()
            ->get();
    }

    /**
     * One summary entry for an operation that changed several rows at once.
     *
     * @param  list<string>  $itemNames
     */
    private function logSummary(Trim $trim, array $itemNames, int $rowCount, ?string $groupName = null): void
    {
        $trim->loadMissing('carModel');

        $this->logger->log(
            'equipment_matrix.changed',
            $trim,
            description: $trim->carModel->name.' · '.$trim->name.($groupName ? ' · '.$groupName : ''),
            changes: [
                'trim' => ['new' => $trim->name],
                'equipment_item' => ['new' => implode(', ', $itemNames)],
                'trim_equipment' => ['new' => $rowCount],
            ],
        );
    }

    /**
     * Comparable state of a cell: "none", "standard" or "optional:<price>".
     */
    private function stateOf(?TrimEquipment $row): string
    {
        return $row === null ? self::NONE : $this->stateFrom($row->availability->value, $row->price_cents);
    }

    private function stateFrom(string $availability, ?int $price): string
    {
        return $availability === EquipmentAvailability::Optional->value ? "optional:{$price}" : $availability;
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
