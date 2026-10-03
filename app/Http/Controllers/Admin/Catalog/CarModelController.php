<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Requests\Admin\Catalog\CarModelRequest;
use App\Http\Requests\Admin\Catalog\SetActiveRequest;
use App\Models\CarModel;
use App\Models\Version;
use App\Support\Like;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CarModelController extends CatalogController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CarModel::class);

        $term = $this->term($request);

        $models = CarModel::query()
            ->withCount(['trims', 'versions'])
            ->when($term !== '', fn ($query) => $query->whereRaw("name like ? escape '!'", [Like::contains($term)]))
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (CarModel $model) => [
                'id' => $model->id,
                'name' => $model->name,
                'slug' => $model->slug,
                'is_active' => $model->is_active,
                'sort_order' => $model->sort_order,
                'trims_count' => $model->trims_count,
                'versions_count' => $model->versions_count,
                // Versions that are offered now and would drop out if this model were deactivated.
                'available_versions' => Version::available()
                    ->whereHas('trim', fn ($trim) => $trim->where('car_model_id', $model->id))
                    ->count(),
            ]);

        return Inertia::render('Admin/Catalog/Models', [
            'items' => $models,
            'filters' => ['q' => $term],
        ]);
    }

    public function store(CarModelRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // The unique index decides when two requests pick the same slug; retry with the next one.
        for ($attempt = 1; ; $attempt++) {
            try {
                CarModel::create([
                    'name' => $data['name'],
                    'slug' => $this->uniqueSlug($data['name']),
                    'is_active' => $data['is_active'] ?? true,
                    'sort_order' => $data['sort_order'] ?? min(65535, (int) CarModel::max('sort_order') + 1),
                ]);

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }

        return back()->with('success', __('Added.'));
    }

    public function update(CarModelRequest $request, CarModel $carModel): RedirectResponse
    {
        // The slug never changes after creation, not even when the name does.
        // An empty sort_order means "keep the current one".
        $carModel->update(array_filter($request->safe()->only(['name', 'is_active', 'sort_order']), fn ($value) => $value !== null));

        return back()->with('success', __('Saved.'));
    }

    public function active(SetActiveRequest $request, CarModel $carModel): RedirectResponse
    {
        return $this->setActive($request, $carModel);
    }

    public function destroy(CarModel $carModel): RedirectResponse
    {
        return $this->deleteIfUnused($carModel, 'car_model', [
            'trims' => $carModel->trims()->count(),
            'versions' => $carModel->versions()->count(),
        ]);
    }

    /**
     * Slug from the name, made unique with -2, -3, ...
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'model';
        $slug = $base;

        for ($suffix = 2; CarModel::where('slug', $slug)->exists(); $suffix++) {
            $slug = "$base-$suffix";
        }

        return $slug;
    }
}
