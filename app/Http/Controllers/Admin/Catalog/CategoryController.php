<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Requests\Admin\Catalog\CategoryRequest;
use App\Http\Requests\Admin\Catalog\SetActiveRequest;
use App\Models\Category;
use App\Support\Like;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends CatalogController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Category::class);

        $term = $this->term($request);

        $categories = Category::query()
            ->withCount('carModels')
            ->when($term !== '', fn ($query) => $query->whereRaw("name like ? escape '!'", [Like::contains($term)]))
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'is_active' => $category->is_active,
                'sort_order' => $category->sort_order,
                'models_count' => $category->car_models_count,
                // A category does not affect availability, so nothing becomes unavailable.
                'available_versions' => 0,
            ]);

        return Inertia::render('Admin/Catalog/Categories', [
            'items' => $categories,
            'filters' => ['q' => $term],
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        for ($attempt = 1; ; $attempt++) {
            try {
                Category::create([
                    'name' => $data['name'],
                    'slug' => $this->uniqueSlug($data['name']),
                    'is_active' => $data['is_active'] ?? true,
                    'sort_order' => $data['sort_order'] ?? min(65535, (int) Category::max('sort_order') + 1),
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

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        // The slug never changes after creation; an empty sort_order keeps the current one.
        $category->update(array_filter($request->safe()->only(['name', 'is_active', 'sort_order']), fn ($value) => $value !== null));

        return back()->with('success', __('Saved.'));
    }

    public function active(SetActiveRequest $request, Category $category): RedirectResponse
    {
        return $this->setActive($request, $category);
    }

    public function destroy(Category $category): RedirectResponse
    {
        return $this->deleteIfUnused($category, 'category', ['models' => $category->carModels()->count()]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;

        for ($suffix = 2; Category::where('slug', $slug)->exists(); $suffix++) {
            $slug = "$base-$suffix";
        }

        return $slug;
    }
}
