<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Enums\EquipmentAvailability;
use App\Enums\EquipmentCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\MatrixCellRequest;
use App\Http\Requests\Admin\Catalog\MatrixGroupRequest;
use App\Models\CarModel;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Setting;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Services\EquipmentMatrix;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Equipment matrix of one car model: rows are equipment items, columns are the trims of the
 * model. Reading takes two queries for the data (entries of the model's trims, items shown);
 * every change goes through EquipmentMatrix, nowhere else.
 */
class MatrixController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', TrimEquipment::class);

        $models = CarModel::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
        $selected = $models->firstWhere('id', $request->integer('model')) ?? $models->first();

        $term = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $category = EquipmentCategory::tryFrom((string) $request->query('category'))?->value;
        $group = $request->query('group') === 'none' ? 'none' : ($request->integer('group') ?: null);
        // On by default: only items with an entry on some trim of this model (the catalog is big).
        $entered = $request->query('entered', '1') !== '0';

        $trims = $selected
            ? Trim::query()->where('car_model_id', $selected->id)->orderBy('sort_order')->orderBy('id')->get()
            : collect();
        $trimIds = $trims->pluck('id');

        // Query 1: the entries of the model's trims with what the matrix needs about the item.
        $entries = $trimIds->isEmpty() ? collect() : TrimEquipment::query()
            ->join('equipment_items', 'equipment_items.id', '=', 'trim_equipment.equipment_item_id')
            ->whereIn('trim_equipment.trim_id', $trimIds)
            ->get([
                'trim_equipment.trim_id', 'trim_equipment.equipment_item_id', 'trim_equipment.availability',
                'trim_equipment.price_cents', 'equipment_items.group_id', 'equipment_items.name as item_name',
            ]);

        // Query 2: the items shown (the group comes with it, one more query for all).
        $items = EquipmentItem::query()
            ->with('group:id,name,selection,is_active')
            ->when($term !== '', fn ($query) => $query->whereRaw("equipment_items.name like ? escape '!'", [Like::contains($term)]))
            ->when($category, fn ($query, $value) => $query->where('equipment_items.category', $value))
            ->when($group === 'none', fn ($query) => $query->whereNull('equipment_items.group_id'))
            ->when(is_int($group), fn ($query) => $query->where('equipment_items.group_id', $group))
            ->when($entered, fn ($query) => $query->whereIn('equipment_items.id', $entries->pluck('equipment_item_id')->unique()->values()))
            ->ordered()
            ->get();

        return Inertia::render('Admin/Catalog/Matrix', [
            'models' => $models,
            'selectedModelId' => $selected?->id,
            'filters' => ['q' => $term, 'category' => $category, 'group' => $group, 'entered' => $entered],
            'categories' => array_map(fn (EquipmentCategory $case) => $case->value, EquipmentCategory::cases()),
            'groups' => OptionGroup::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'category', 'selection', 'is_active']),
            'vatRateBp' => Setting::vatRateBp(),
            'trims' => $trims->map(fn (Trim $trim) => [
                'id' => $trim->id,
                'name' => $trim->name,
                'is_active' => $trim->is_active,
                'standard_count' => $entries->where('trim_id', $trim->id)->where('availability', EquipmentAvailability::Standard)->count(),
                'optional_count' => $entries->where('trim_id', $trim->id)->where('availability', EquipmentAvailability::Optional)->count(),
            ])->values(),
            'items' => $items->map(fn (EquipmentItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category->value,
                'group_id' => $item->group_id,
                'group_name' => $item->group?->name,
                'selection' => $item->group?->selection->value,
                'swatch_hex' => $item->swatch_hex,
                'is_active' => $item->is_active,
                // Why the cells are disabled: new assignments need an offerable item.
                'locked' => ! $item->is_active ? 'item' : ($item->group !== null && ! $item->group->is_active ? 'group' : null),
            ])->values(),
            'entries' => $entries->map(fn (TrimEquipment $row) => [
                'trim_id' => $row->trim_id,
                'item_id' => $row->equipment_item_id,
                'group_id' => $row->group_id,
                'item_name' => $row->item_name,
                'availability' => $row->availability->value,
                'price_cents' => $row->price_cents,
            ])->values(),
        ]);
    }

    public function cell(MatrixCellRequest $request, EquipmentMatrix $matrix): JsonResponse
    {
        $matrix->setCell(
            $request->trim(),
            $request->item(),
            $request->input('availability'),
            $request->targetPriceCents(),
            $request->expected(),
            $request->previous(),
            $request->filled('expected_standard_item_id') ? $request->integer('expected_standard_item_id') : null,
        );

        return response()->json(['message' => __('Saved.')]);
    }

    public function group(MatrixGroupRequest $request, EquipmentMatrix $matrix): JsonResponse
    {
        $matrix->removeGroup($request->trim(), $request->group(), $request->input('expected'));

        return response()->json(['message' => __('The group was removed from the line.')]);
    }
}
