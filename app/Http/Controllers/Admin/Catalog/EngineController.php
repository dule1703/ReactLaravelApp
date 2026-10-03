<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Enums\FuelType;
use App\Http\Requests\Admin\Catalog\EngineRequest;
use App\Http\Requests\Admin\Catalog\SetActiveRequest;
use App\Models\Engine;
use App\Models\Version;
use App\Support\Like;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EngineController extends CatalogController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Engine::class);

        $term = $this->term($request);

        $engines = Engine::query()
            ->withCount('versions')
            ->when($term !== '', fn ($query) => $query->whereRaw("name like ? escape '!'", [Like::contains($term)]))
            ->orderBy('name')->orderBy('power_kw')->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Engine $engine) => [
                'id' => $engine->id,
                'name' => $engine->name,
                'fuel_type' => $engine->fuel_type->value,
                'power_kw' => $engine->power_kw,
                'is_active' => $engine->is_active,
                'versions_count' => $engine->versions_count,
                'available_versions' => Version::available()->where('versions.engine_id', $engine->id)->count(),
            ]);

        return Inertia::render('Admin/Catalog/Engines', [
            'items' => $engines,
            'filters' => ['q' => $term],
            'fuelTypes' => array_map(fn (FuelType $type) => $type->value, FuelType::cases()),
        ]);
    }

    public function store(EngineRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Engine::create($data + ['is_active' => true]);

        return back()->with('success', __('Added.'));
    }

    public function update(EngineRequest $request, Engine $engine): RedirectResponse
    {
        $engine->update($request->validated());

        return back()->with('success', __('Saved.'));
    }

    public function active(SetActiveRequest $request, Engine $engine): RedirectResponse
    {
        return $this->setActive($request, $engine);
    }

    public function destroy(Engine $engine): RedirectResponse
    {
        return $this->deleteIfUnused($engine, 'engine', ['versions' => $engine->versions()->count()]);
    }
}
