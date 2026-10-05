<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Requests\Admin\Catalog\OptionGroupRequest;
use App\Http\Requests\Admin\Catalog\SetActiveRequest;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OptionGroupController extends CatalogController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', OptionGroup::class);

        $term = $this->term($request);

        $groups = OptionGroup::query()
            ->withCount([
                'items',
                'items as swatch_items_count' => fn (Builder $items) => $items->whereNotNull('swatch_hex'),
            ])
            ->when($term !== '', fn ($query) => $query->whereRaw("name like ? escape '!'", [Like::contains($term)]))
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (OptionGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'category' => $group->category->value,
                'selection' => $group->selection->value,
                'uses_swatch' => $group->uses_swatch,
                'sort_order' => $group->sort_order,
                'is_active' => $group->is_active,
                'items_count' => $group->items_count,
                'swatch_items_count' => $group->swatch_items_count,
                // A group does not affect Version::available(); it only takes its items out of new offers.
                'available_versions' => 0,
            ]);

        return Inertia::render('Admin/Catalog/OptionGroups', [
            'items' => $groups,
            'filters' => ['q' => $term],
        ]);
    }

    public function store(OptionGroupRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $attributes = [
            'name' => $data['name'],
            'category' => $data['category'],
            'selection' => $data['selection'],
            'uses_swatch' => (bool) ($data['uses_swatch'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => $data['sort_order'] ?? min(65535, (int) OptionGroup::max('sort_order') + 1),
        ];

        // The unique index decides when two requests pick the same slug; retry with the next one.
        for ($attempt = 1; ; $attempt++) {
            try {
                OptionGroup::create($attributes + ['slug' => $this->uniqueSlug($data['name'])]);

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }

        return back()->with('success', __('Added.'));
    }

    public function update(OptionGroupRequest $request, OptionGroup $optionGroup): RedirectResponse
    {
        $data = $request->validated();

        // The slug never changes after creation; an empty sort_order keeps the current one.
        $attributes = array_filter([
            'name' => $data['name'],
            'category' => $data['category'],
            'selection' => $data['selection'],
            'uses_swatch' => (bool) ($data['uses_swatch'] ?? false),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($value) => $value !== null);

        $categoryChanged = $data['category'] !== $optionGroup->category->value;

        // The group first, then its items, in one transaction: every change goes through the
        // models (activity log) and the items' category never differs from the group's.
        DB::transaction(function () use ($optionGroup, $attributes, $categoryChanged) {
            $optionGroup->update($attributes);

            if ($categoryChanged) {
                $optionGroup->items()->get()->each(fn (EquipmentItem $item) => $item->update(['category' => $attributes['category']]));
            }
        });

        return back()->with('success', __('Saved.'));
    }

    public function active(SetActiveRequest $request, OptionGroup $optionGroup): RedirectResponse
    {
        return $this->setActive($request, $optionGroup);
    }

    public function destroy(OptionGroup $optionGroup): RedirectResponse
    {
        return $this->deleteIfUnused($optionGroup, 'option_group', ['items' => $optionGroup->items()->count()]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'group';
        $slug = $base;

        for ($suffix = 2; OptionGroup::where('slug', $slug)->exists(); $suffix++) {
            $slug = "$base-$suffix";
        }

        return $slug;
    }
}
