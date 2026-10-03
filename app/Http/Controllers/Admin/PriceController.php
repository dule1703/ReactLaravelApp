<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EquipmentAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkPriceRequest;
use App\Http\Requests\Admin\UpdateEquipmentPriceRequest;
use App\Http\Requests\Admin\UpdateVatRateRequest;
use App\Http\Requests\Admin\UpdateVersionPriceRequest;
use App\Models\CarModel;
use App\Models\Setting;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\Version;
use App\Services\BulkPriceChange;
use App\Support\Money;
use App\Support\Vat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin price screen: net prices of versions and optional equipment, the VAT rate and bulk
 * changes. Prices are stored NET; gross is always derived with the current rate. The server
 * parses what was typed and derives the net price itself.
 */
class PriceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Version::class);

        $models = CarModel::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
        $selected = $models->firstWhere('id', (int) $request->query('model')) ?? $models->first();
        $rate = Setting::vatRateBp();

        $trims = $selected
            ? Trim::query()->where('car_model_id', $selected->id)->orderBy('sort_order')->orderBy('id')
                ->with([
                    'versions' => fn ($query) => $query->with(['engine', 'transmission'])->orderBy('id'),
                    'trimEquipment' => fn ($query) => $query
                        ->where('availability', EquipmentAvailability::Optional->value)
                        ->with('equipmentItem')->orderBy('id'),
                ])->get()
            : collect();

        return Inertia::render('Admin/Prices', [
            'vat' => ['rate_bp' => $rate, 'rate_percent' => Money::formatPercentBp($rate)],
            'models' => $models,
            'selectedModelId' => $selected?->id,
            'trims' => $trims->map(fn (Trim $trim) => [
                'id' => $trim->id,
                'name' => $trim->name,
                'is_active' => $trim->is_active,
                'versions' => $trim->versions->map(fn (Version $version) => [
                    'id' => $version->id,
                    'engine' => $version->engine->name,
                    'fuel' => $version->engine->fuel_type->value,
                    'power_kw' => $version->engine->power_kw,
                    'transmission' => $version->transmission->name,
                    'drive' => $version->transmission->drive->value,
                    'is_active' => $version->is_active,
                    'net' => $version->base_price_cents,
                    'gross' => Vat::grossFromNet($version->base_price_cents, $rate),
                ])->values(),
                'equipment' => $trim->trimEquipment->map(fn (TrimEquipment $row) => [
                    'id' => $row->id,
                    'name' => $row->equipmentItem->name,
                    'category' => $row->equipmentItem->category->value,
                    'net' => (int) $row->price_cents,
                    'gross' => Vat::grossFromNet((int) $row->price_cents, $rate),
                ])->values(),
            ])->values(),
        ]);
    }

    public function updateVersion(UpdateVersionPriceRequest $request, Version $version): RedirectResponse
    {
        // The net price is derived here from the typed amount; the browser's value is never used.
        $version->update(['base_price_cents' => $request->netCents()]);

        return back()->with('success', __('Price saved.'));
    }

    public function updateEquipment(UpdateEquipmentPriceRequest $request, TrimEquipment $trimEquipment): RedirectResponse
    {
        $trimEquipment->update(['price_cents' => $request->netCents()]);

        return back()->with('success', __('Price saved.'));
    }

    public function updateVat(UpdateVatRateRequest $request): RedirectResponse
    {
        // Stored net prices are untouched; only the derived gross prices change.
        Setting::setVatRateBp($request->rateBp());

        return back()->with('success', __('VAT rate saved.'));
    }

    public function bulkPreview(BulkPriceRequest $request, BulkPriceChange $change): JsonResponse
    {
        return response()->json($change->plan($request->params()))
            ->header('Cache-Control', 'no-store, private');
    }

    public function bulkApply(BulkPriceRequest $request, BulkPriceChange $change): JsonResponse
    {
        $count = $change->apply($request->params(), $request->input('token'), $request->boolean('confirm_large'));

        return response()->json(['updated' => $count, 'message' => __('Prices updated.')]);
    }
}
