<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Requests\Admin\Catalog\CarModelRequest;
use App\Http\Requests\Admin\Catalog\SetActiveRequest;
use App\Models\CarModel;
use App\Models\Category;
use App\Models\Version;
use App\Services\CarModelCategories;
use App\Services\CatalogImages;
use App\Support\Like;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CarModelController extends CatalogController
{
    public function __construct(
        private readonly CarModelCategories $categories,
        private readonly CatalogImages $images,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CarModel::class);

        $term = $this->term($request);
        $categoryId = $request->integer('category') ?: null;

        $models = CarModel::query()
            ->with('categories')
            ->withCount(['trims', 'versions'])
            ->when($term !== '', fn ($query) => $query->whereRaw("name like ? escape '!'", [Like::contains($term)]))
            ->when($categoryId, fn ($query, $id) => $query->whereHas('categories', fn ($category) => $category->where('categories.id', $id)))
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (CarModel $model) => [
                'id' => $model->id,
                'name' => $model->name,
                'slug' => $model->slug,
                'image_url' => $model->imageUrl(),
                'is_active' => $model->is_active,
                'sort_order' => $model->sort_order,
                'categories' => $model->categories->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'is_active' => $category->is_active,
                ])->values(),
                'trims_count' => $model->trims_count,
                'versions_count' => $model->versions_count,
                // Versions that are offered now and would drop out if this model were deactivated.
                'available_versions' => Version::available()
                    ->whereHas('trim', fn ($trim) => $trim->where('car_model_id', $model->id))
                    ->count(),
            ]);

        return Inertia::render('Admin/Catalog/Models', [
            'items' => $models,
            'filters' => ['q' => $term, 'category' => $categoryId],
            'categories' => Category::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function store(CarModelRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $attributes = [
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? min(65535, (int) CarModel::max('sort_order') + 1),
        ];

        // The unique index decides when two requests pick the same slug; retry with the next one.
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->persist($request, null, $attributes + ['slug' => $this->uniqueSlug($data['name'])]);

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
        // The slug never changes after creation; an empty sort_order keeps the current one.
        $attributes = array_filter(
            $request->safe()->only(['name', 'is_active', 'sort_order']),
            fn ($value) => $value !== null,
        );

        $this->persist($request, $carModel, $attributes);

        return back()->with('success', __('Saved.'));
    }

    public function active(SetActiveRequest $request, CarModel $carModel): RedirectResponse
    {
        return $this->setActive($request, $carModel);
    }

    public function destroy(CarModel $carModel): RedirectResponse
    {
        $imagePath = $carModel->image_path;

        return $this->deleteIfUnused(
            $carModel,
            'car_model',
            // Only trims and versions block; category links are detached together with the delete.
            ['trims' => $carModel->trims()->count(), 'versions' => $carModel->versions()->count()],
            beforeDelete: fn (CarModel $model) => $this->categories->sync($model, []),
            afterDelete: fn () => $this->images->forget($imagePath),
        );
    }

    /**
     * Save the model, its categories and its image as one unit.
     *
     * CatalogImages stores the new file first, then the database is written in a transaction
     * (model fields, image path, categories). The OLD file is deleted only after that commit; if
     * the write fails, the NEW file is removed and the old image stays.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persist(CarModelRequest $request, ?CarModel $model, array $attributes): CarModel
    {
        return $this->images->save(
            'catalog/models',
            $request->file('image'),
            $request->boolean('remove_image'),
            $model?->image_path,
            fn (array $imageAttributes) => DB::transaction(function () use ($request, $model, $attributes, $imageAttributes) {
                $attributes += $imageAttributes;

                if ($model === null) {
                    $saved = CarModel::create($attributes);
                } else {
                    $model->update($attributes);
                    $saved = $model;
                }

                if ($request->boolean('sync_categories')) {
                    $this->categories->sync($saved, $request->input('category_ids', []));
                }

                return $saved;
            }),
        );
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
