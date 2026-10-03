<?php

namespace App\Services;

use App\Enums\EquipmentAvailability;
use App\Models\CarModel;
use App\Models\Setting;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\Version;
use App\Support\Money;
use App\Support\Vat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bulk price change over the versions and the optional equipment of a model (or one trim).
 *
 * The new GROSS price is rounded to a whole euro (half up) and the net price is derived from it:
 *   percent: gross' = roundToEuro(grossFromNet(net) * (10000 + pct_bp) / 10000)
 *   amount:  net mode   -> gross' = roundToEuro(grossFromNet(net + delta))
 *            gross mode -> gross' = roundToEuro(grossFromNet(net) + delta)
 *   net' = netFromGross(gross')
 * plan() is pure (the preview); apply() recomputes everything on the server inside a
 * transaction and only proceeds when the preview token still matches, so what is applied is
 * exactly what was reviewed. Every price goes through the models (old/new in the activity log)
 * and one summary entry is written; a failure rolls back prices and log entries together.
 */
class BulkPriceChange
{
    /** A single item changing by more than this share needs an explicit confirmation. */
    private const LARGE_CHANGE_PERCENT = 10;

    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @param  array{model_id: int, trim_id: ?int, targets: list<string>, type: string, value: int, mode: string}  $params
     * @return array{items: list<array<string, mixed>>, token: string, requires_confirmation: bool}
     */
    public function plan(array $params): array
    {
        return $this->summarize($this->build($params, lock: false), Setting::vatRateBp());
    }

    /**
     * @param  array{model_id: int, trim_id: ?int, targets: list<string>, type: string, value: int, mode: string}  $params
     * @return array{versions: int, equipment: int}
     */
    public function apply(array $params, ?string $token, bool $confirmLarge): array
    {
        return DB::transaction(function () use ($params, $token, $confirmLarge) {
            $rate = Setting::vatRateBp();
            $rows = $this->build($params, lock: true);
            $plan = $this->summarize($rows, $rate);

            if ($token === null || ! hash_equals($plan['token'], $token)) {
                abort(409, __('Prices changed in the meantime. Review the change again.'));
            }

            if ($plan['requires_confirmation'] && ! $confirmLarge) {
                throw ValidationException::withMessages(['confirm_large' => __('This change moves some prices by more than 10%. Confirm it explicitly.')]);
            }

            $count = ['versions' => 0, 'equipment' => 0];

            foreach ($rows as $row) {
                // Through the model: hooks run and the old/new price lands in the activity log.
                $row['model']->update([$row['column'] => $row['new_net']]);
                $count[$row['kind'] === 'version' ? 'versions' : 'equipment']++;
            }

            $this->logger->log(
                'price.bulk_updated',
                description: $this->describe($params),
                changes: array_filter([
                    'version' => $count['versions'] ? ['new' => $count['versions']] : null,
                    'trim_equipment' => $count['equipment'] ? ['new' => $count['equipment']] : null,
                ]),
            );

            return $count;
        });
    }

    /**
     * @param  array{model_id: int, trim_id: ?int, targets: list<string>, type: string, value: int, mode: string}  $params
     * @return list<array<string, mixed>> only the rows whose price changes, each with its model
     */
    private function build(array $params, bool $lock): array
    {
        $rate = Setting::vatRateBp();
        $rows = [];

        if (in_array('versions', $params['targets'], true)) {
            $query = Version::query()->with(['trim', 'engine', 'transmission'])
                ->whereHas('trim', fn ($trim) => $this->scopeTrim($trim, $params))
                ->orderBy('id');

            foreach ($lock ? $query->lockForUpdate()->get() : $query->get() as $version) {
                $rows[] = $this->row('version', $version, 'base_price_cents', $version->base_price_cents, 1, $params, $rate, sprintf(
                    '%s - %s, %s',
                    $version->trim->name,
                    $version->engine->name,
                    $version->transmission->name,
                ));
            }
        }

        if (in_array('equipment', $params['targets'], true)) {
            $query = TrimEquipment::query()->with(['trim', 'equipmentItem'])
                ->where('availability', EquipmentAvailability::Optional->value)
                ->whereHas('trim', fn ($trim) => $this->scopeTrim($trim, $params))
                ->orderBy('id');

            foreach ($lock ? $query->lockForUpdate()->get() : $query->get() as $row) {
                $rows[] = $this->row('trim_equipment', $row, 'price_cents', (int) $row->price_cents, 0, $params, $rate, sprintf(
                    '%s - %s',
                    $row->trim->name,
                    $row->equipmentItem->name,
                ));
            }
        }

        $rows = array_values(array_filter($rows, fn (array $row) => $row['new_net'] !== $row['old_net']));

        if ($rows === []) {
            throw ValidationException::withMessages(['value' => __('The change does not modify any price.')]);
        }

        return $rows;
    }

    /**
     * @param  array{model_id: int, trim_id: ?int, targets: list<string>, type: string, value: int, mode: string}  $params
     * @return array<string, mixed>
     */
    private function row(string $kind, Model $model, string $column, int $oldNet, int $minNet, array $params, int $rate, string $label): array
    {
        $oldGross = Vat::grossFromNet($oldNet, $rate);

        $newGross = match (true) {
            $params['type'] === 'percent' => intdiv($oldGross * (10000 + $params['value']) + 500_000, 1_000_000) * 100,
            $params['mode'] === 'gross' => $this->roundGross($oldGross + $params['value'], $label),
            default => $this->roundGross(Vat::grossFromNet($this->nonNegative($oldNet + $params['value'], $label), $rate), $label),
        };

        $newNet = Vat::netFromGross($newGross, $rate);

        if ($newNet < $minNet || $newNet > Money::MAX_PRICE_CENTS) {
            throw ValidationException::withMessages(['value' => __('The new price of ":item" is out of range.', ['item' => $label])]);
        }

        return [
            'kind' => $kind,
            'model' => $model,
            'column' => $column,
            'label' => $label,
            'old_net' => $oldNet,
            'old_gross' => $oldGross,
            'new_net' => $newNet,
            'new_gross' => $newGross,
        ];
    }

    private function roundGross(int $gross, string $label): int
    {
        return Money::roundToEuro($this->nonNegative($gross, $label));
    }

    private function nonNegative(int $cents, string $label): int
    {
        if ($cents < 0) {
            throw ValidationException::withMessages(['value' => __('The new price of ":item" is out of range.', ['item' => $label])]);
        }

        return $cents;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{items: list<array<string, mixed>>, token: string, requires_confirmation: bool}
     */
    private function summarize(array $rows, int $rate): array
    {
        $large = false;
        $items = [];

        foreach ($rows as $row) {
            $delta = abs($row['new_net'] - $row['old_net']);
            $large = $large || ($row['old_net'] === 0 ? true : $delta * 100 > $row['old_net'] * self::LARGE_CHANGE_PERCENT);

            $items[] = [
                'kind' => $row['kind'],
                'id' => $row['model']->getKey(),
                'label' => $row['label'],
                'old_net' => $row['old_net'],
                'old_gross' => $row['old_gross'],
                'new_net' => $row['new_net'],
                'new_gross' => $row['new_gross'],
            ];
        }

        $payload = json_encode([$rate, array_map(fn (array $item) => [$item['kind'], $item['id'], $item['old_net'], $item['new_net']], $items)]);

        return [
            'items' => $items,
            'token' => hash_hmac('sha256', (string) $payload, (string) config('app.key')),
            'requires_confirmation' => $large,
        ];
    }

    /**
     * @param  array{model_id: int, trim_id: ?int, targets: list<string>, type: string, value: int, mode: string}  $params
     */
    private function describe(array $params): string
    {
        $sign = $params['value'] < 0 ? '-' : '+';
        $change = $params['type'] === 'percent'
            ? $sign.Money::formatPercentBp(abs($params['value'])).'%'
            : sprintf('%s%d,%02d EUR (%s)', $sign, intdiv(abs($params['value']), 100), abs($params['value']) % 100, $params['mode']);

        return sprintf(
            '%s; model: %s; trim: %s; targets: %s',
            $change,
            CarModel::find($params['model_id'])?->name,
            $params['trim_id'] ? Trim::find($params['trim_id'])?->name : 'all',
            implode(',', $params['targets']),
        );
    }

    /**
     * @param  Builder<Trim>  $trim
     * @param  array{model_id: int, trim_id: ?int}  $params
     */
    private function scopeTrim($trim, array $params): void
    {
        $trim->where('car_model_id', $params['model_id']);

        if ($params['trim_id'] !== null) {
            $trim->whereKey($params['trim_id']);
        }
    }
}
