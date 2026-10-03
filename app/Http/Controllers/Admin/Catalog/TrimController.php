<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Requests\Admin\Catalog\SetActiveRequest;
use App\Http\Requests\Admin\Catalog\TrimRequest;
use App\Models\CarModel;
use App\Models\Trim;
use App\Models\Version;
use App\Support\Like;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TrimController extends CatalogController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Trim::class);

        $term = $this->term($request);

        $trims = Trim::query()
            ->with('carModel')
            ->withCount(['versions', 'trimEquipment'])
            ->when($term !== '', fn ($query) => $query->whereRaw("trims.name like ? escape '!'", [Like::contains($term)]))
            ->orderBy('car_model_id')->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Trim $trim) => [
                'id' => $trim->id,
                'name' => $trim->name,
                'car_model_id' => $trim->car_model_id,
                'model_name' => $trim->carModel->name,
                'is_active' => $trim->is_active,
                'sort_order' => $trim->sort_order,
                'versions_count' => $trim->versions_count,
                'equipment_count' => $trim->trim_equipment_count,
                // Active, but not offered because its car model is inactive.
                'unavailable_reason' => $trim->is_active && ! $trim->carModel->is_active ? 'model_inactive' : null,
                'available_versions' => Version::available()->where('versions.trim_id', $trim->id)->count(),
            ]);

        return Inertia::render('Admin/Catalog/Trims', [
            'items' => $trims,
            'filters' => ['q' => $term],
            'models' => CarModel::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function store(TrimRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Trim::create([
            'car_model_id' => $data['car_model_id'],
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order']
                ?? min(65535, (int) Trim::where('car_model_id', $data['car_model_id'])->max('sort_order') + 1),
        ]);

        return back()->with('success', __('Added.'));
    }

    public function update(TrimRequest $request, Trim $trim): RedirectResponse
    {
        // The car model of a trim never changes; an empty sort_order keeps the current one.
        $trim->update(array_filter($request->safe()->only(['name', 'is_active', 'sort_order']), fn ($value) => $value !== null));

        return back()->with('success', __('Saved.'));
    }

    public function active(SetActiveRequest $request, Trim $trim): RedirectResponse
    {
        return $this->setActive($request, $trim);
    }

    public function destroy(Trim $trim): RedirectResponse
    {
        return $this->deleteIfUnused($trim, 'trim', [
            'versions' => $trim->versions()->count(),
            'equipment' => $trim->trimEquipment()->count(),
        ]);
    }
}
