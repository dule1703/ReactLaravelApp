<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Enums\EquipmentCategory;
use App\Http\Requests\Admin\Catalog\EquipmentItemRequest;
use App\Http\Requests\Admin\Catalog\SetActiveRequest;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Services\CatalogImages;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EquipmentItemController extends CatalogController
{
    public function __construct(private readonly CatalogImages $images) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', EquipmentItem::class);

        $term = $this->term($request);
        $category = EquipmentCategory::tryFrom((string) $request->query('category'))?->value;
        $group = $request->query('group') === 'none' ? 'none' : ($request->integer('group') ?: null);
        $status = in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : null;

        $items = EquipmentItem::query()
            ->with('group')
            // Counts in the same query (no N+1): lines = trims the item is on, standard = as standard item.
            ->withCount([
                'trimEquipment as lines_count',
                'trimEquipment as standard_lines_count' => fn (Builder $rows) => $rows->where('availability', 'standard'),
            ])
            ->when($term !== '', fn ($query) => $query->whereRaw("equipment_items.name like ? escape '!'", [Like::contains($term)]))
            ->when($category, fn ($query, $value) => $query->where('equipment_items.category', $value))
            ->when($group === 'none', fn ($query) => $query->whereNull('equipment_items.group_id'))
            ->when(is_int($group), fn ($query) => $query->where('equipment_items.group_id', $group))
            ->when($status === 'active', fn ($query) => $query->where('equipment_items.is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('equipment_items.is_active', false))
            ->ordered()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(function (EquipmentItem $item) {
                $single = $item->group?->selection->value === 'single';

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'category' => $item->category->value,
                    'group_id' => $item->group_id,
                    'group_name' => $item->group?->name,
                    'swatch_hex' => $item->swatch_hex,
                    'image_url' => $item->imageUrl(),
                    'sort_order' => $item->sort_order,
                    'is_active' => $item->is_active,
                    'lines_count' => $item->lines_count,
                    // Standard item of a single-choice group on this many trims: cannot be deactivated.
                    'standard_single_lines' => $single ? $item->standard_lines_count : 0,
                    // Active, but its group is inactive: not offered in new offers.
                    'group_inactive' => $item->is_active && $item->group !== null && ! $item->group->is_active,
                    'available_versions' => 0,
                ];
            });

        return Inertia::render('Admin/Catalog/Equipment', [
            'items' => $items,
            'filters' => ['q' => $term, 'category' => $category, 'group' => $group, 'status' => $status],
            'categories' => array_map(fn (EquipmentCategory $case) => $case->value, EquipmentCategory::cases()),
            'groups' => OptionGroup::query()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (OptionGroup $option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'category' => $option->category->value,
                    'selection' => $option->selection->value,
                    'uses_swatch' => $option->uses_swatch,
                    'is_active' => $option->is_active,
                ])->values(),
        ]);
    }

    public function store(EquipmentItemRequest $request): RedirectResponse
    {
        $this->persist($request, null);

        return back()->with('success', __('Added.'));
    }

    public function update(EquipmentItemRequest $request, EquipmentItem $equipmentItem): RedirectResponse
    {
        $this->persist($request, $equipmentItem);

        return back()->with('success', __('Saved.'));
    }

    public function active(SetActiveRequest $request, EquipmentItem $equipmentItem): RedirectResponse
    {
        Gate::authorize('update', $equipmentItem);

        // The standard item of a single-choice group cannot just disappear from a trim.
        if (! $request->boolean('is_active') && $equipmentItem->is_active && ($standard = $equipmentItem->standardLinesInSingleGroup()) > 0) {
            return back()->with('error', __('The item is the standard item of a single-choice group on :count trims; replace it in the equipment matrix before deactivating it.', ['count' => $standard]));
        }

        return $this->setActive($request, $equipmentItem);
    }

    public function destroy(EquipmentItem $equipmentItem): RedirectResponse
    {
        $imagePath = $equipmentItem->image_path;

        return $this->deleteIfUnused(
            $equipmentItem,
            'equipment_item',
            ['lines' => $equipmentItem->trimEquipment()->count()],
            afterDelete: fn () => $this->images->forget($imagePath),
        );
    }

    /**
     * Save the item and its image as one unit (CatalogImages: new file first, database in a
     * transaction, the old file only after the commit).
     */
    private function persist(EquipmentItemRequest $request, ?EquipmentItem $item): EquipmentItem
    {
        $data = $request->validated();

        $attributes = [
            'name' => $data['name'],
            'category' => $data['category'],
            'group_id' => $data['group_id'] ?? null,
            'swatch_hex' => isset($data['swatch_hex']) ? strtoupper($data['swatch_hex']) : null,
        ];

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        } elseif ($item === null) {
            $attributes['is_active'] = true;
        }

        if (isset($data['sort_order'])) {
            $attributes['sort_order'] = (int) $data['sort_order'];
        } elseif ($item === null) {
            $attributes['sort_order'] = min(65535, (int) EquipmentItem::where('category', $data['category'])->max('sort_order') + 1);
        }

        return $this->images->save(
            'catalog/equipment',
            $request->file('image'),
            $request->boolean('remove_image'),
            $item?->image_path,
            fn (array $imageAttributes) => DB::transaction(function () use ($item, $attributes, $imageAttributes) {
                $attributes += $imageAttributes;

                if ($item === null) {
                    return EquipmentItem::create($attributes);
                }

                $item->update($attributes);

                return $item;
            }),
        );
    }
}
