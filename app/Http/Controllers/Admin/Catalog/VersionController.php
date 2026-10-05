<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Requests\Admin\Catalog\SetActiveRequest;
use App\Http\Requests\Admin\Catalog\StoreVersionRequest;
use App\Models\CarModel;
use App\Models\Engine;
use App\Models\Setting;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\Version;
use App\Support\Like;
use App\Support\Money;
use App\Support\Vat;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Versions: list, add and (de)activate. A version's trim/engine/transmission never change after
 * creation and its price is edited on /admin/prices, not here.
 */
class VersionController extends CatalogController
{
    private const STATUSES = ['active', 'inactive', 'unavailable'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Version::class);

        $term = $this->term($request);
        $modelId = $request->integer('model') ?: null;
        $trimId = $request->integer('trim') ?: null;
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;
        $rate = Setting::vatRateBp();

        $versions = Version::query()
            ->join('trims', 'trims.id', '=', 'versions.trim_id')
            ->join('car_models', 'car_models.id', '=', 'trims.car_model_id')
            ->select('versions.*')
            ->with(['trim.carModel', 'engine', 'transmission'])
            ->when($modelId, fn ($query, $id) => $query->where('trims.car_model_id', $id))
            ->when($trimId, fn ($query, $id) => $query->where('versions.trim_id', $id))
            ->when($status === 'active', fn ($query) => $query->where('versions.is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('versions.is_active', false))
            // Active, but not offered: judged by Version::available(), the only place of that rule.
            ->when($status === 'unavailable', fn ($query) => $query
                ->where('versions.is_active', true)
                ->whereNotIn('versions.id', Version::available()->select('versions.id')))
            ->when($term !== '', function ($query) use ($term) {
                $like = Like::contains($term);

                $query->where(fn ($inner) => $inner
                    ->whereRaw("trims.name like ? escape '!'", [$like])
                    ->orWhereRaw("car_models.name like ? escape '!'", [$like])
                    ->orWhereHas('engine', fn ($engine) => $engine->whereRaw("name like ? escape '!'", [$like]))
                    ->orWhereHas('transmission', fn ($transmission) => $transmission->whereRaw("name like ? escape '!'", [$like])));
            })
            ->orderBy('car_models.sort_order')->orderBy('trims.sort_order')->orderBy('versions.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // One query for the whole page, through the scope (no copy of the availability rule).
        $availableIds = Version::available()
            ->whereIn('versions.id', $versions->getCollection()->pluck('id'))
            ->pluck('versions.id')
            ->all();

        $versions->through(function (Version $version) use ($rate, $availableIds) {
            $available = in_array($version->id, $availableIds, true);

            return [
                'id' => $version->id,
                'name' => $version->trim->carModel->name.' '.$version->trim->name.' · '.$version->engine->name.' · '.$version->transmission->name,
                'model_id' => $version->trim->car_model_id,
                'model_name' => $version->trim->carModel->name,
                'trim_name' => $version->trim->name,
                'engine' => $version->engine->name,
                'fuel' => $version->engine->fuel_type->value,
                'power_kw' => $version->engine->power_kw,
                'transmission' => $version->transmission->name,
                'drive' => $version->transmission->drive->value,
                'net' => $version->base_price_cents,
                'gross' => Vat::grossFromNet($version->base_price_cents, $rate),
                'is_active' => $version->is_active,
                'available' => $available,
                // Display only: which parents are inactive (the rule itself is Version::available()).
                'inactive_parents' => $version->is_active && ! $available ? array_keys(array_filter([
                    'car_model' => ! $version->trim->carModel->is_active,
                    'trim' => ! $version->trim->is_active,
                    'engine' => ! $version->engine->is_active,
                    'transmission' => ! $version->transmission->is_active,
                ])) : [],
                'available_versions' => $available ? 1 : 0,
                'price_url' => route('prices.index', ['model' => $version->trim->car_model_id]),
            ];
        });

        return Inertia::render('Admin/Catalog/Versions', [
            'items' => $versions,
            'filters' => ['q' => $term, 'model' => $modelId, 'trim' => $trimId, 'status' => $status],
            'vat' => ['rate_bp' => $rate, 'rate_percent' => Money::formatPercentBp($rate)],
            'models' => CarModel::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'is_active']),
            'trims' => Trim::query()->with('carModel:id,name')->orderBy('car_model_id')->orderBy('sort_order')->get()
                ->map(fn (Trim $trim) => [
                    'id' => $trim->id,
                    'name' => $trim->name,
                    'car_model_id' => $trim->car_model_id,
                    'model_name' => $trim->carModel->name,
                    'is_active' => $trim->is_active,
                ])->values(),
            'engines' => Engine::query()->orderBy('name')->orderBy('power_kw')->get()
                ->map(fn (Engine $engine) => [
                    'id' => $engine->id,
                    'name' => $engine->name,
                    'fuel' => $engine->fuel_type->value,
                    'power_kw' => $engine->power_kw,
                    'is_active' => $engine->is_active,
                ])->values(),
            'transmissions' => Transmission::query()->orderBy('name')->get()
                ->map(fn (Transmission $transmission) => [
                    'id' => $transmission->id,
                    'name' => $transmission->name,
                    'drive' => $transmission->drive->value,
                    'is_active' => $transmission->is_active,
                ])->values(),
        ]);
    }

    public function store(StoreVersionRequest $request): RedirectResponse
    {
        try {
            // The net price is derived on the server from what was typed (net or gross).
            Version::create([
                'trim_id' => $request->integer('trim_id'),
                'engine_id' => $request->integer('engine_id'),
                'transmission_id' => $request->integer('transmission_id'),
                'base_price_cents' => $request->netCents(),
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The same combination created by a concurrent request.
            throw ValidationException::withMessages(['trim_id' => __('This combination of trim, engine and transmission already exists.')]);
        }

        return back()->with('success', __('Added.'));
    }

    public function active(SetActiveRequest $request, Version $version): RedirectResponse
    {
        return $this->setActive($request, $version);
    }

    public function destroy(Version $version): RedirectResponse
    {
        return $this->deleteIfUnused($version, 'version', $this->dependencies($version));
    }

    /**
     * What depends on a version. Nothing, on purpose: an offer is a snapshot (names and prices
     * as text) and has no foreign key or id of a version, so deleting or deactivating a version
     * cannot affect any offer. If a later phase ever stores a reference to a version, its check
     * goes here, in this one place.
     *
     * @return array<string, int>
     */
    private function dependencies(Version $version): array
    {
        return [];
    }
}
