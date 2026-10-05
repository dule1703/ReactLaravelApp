<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Enums\EquipmentCategory;
use App\Enums\OptionSelection;
use App\Models\OptionGroup;
use App\Models\Trim;
use App\Support\OptionGroupRule;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Option group form. Changes of a group that already has items are checked against the state
 * they would leave behind:
 *  - a new category also changes the category of all items, so it needs an explicit confirmation;
 *  - multiple -> single only if every trim that offers the group still has exactly one standard item;
 *  - turning swatches off only when no item has a swatch.
 */
class OptionGroupRequest extends CatalogRequest
{
    protected function modelClass(): string
    {
        return OptionGroup::class;
    }

    protected function routeParameter(): string
    {
        return 'optionGroup';
    }

    public function rules(): array
    {
        $rules = $this->baseRules();
        $rules['name'][] = function (string $attribute, mixed $value, Closure $fail) {
            if ($this->nameTaken((string) $value, fn ($query) => $query)) {
                $fail(__('An option group with this name already exists.'));
            }
        };
        $rules['category'] = ['required', Rule::enum(EquipmentCategory::class)];
        $rules['selection'] = ['required', Rule::enum(OptionSelection::class)];
        $rules['uses_swatch'] = ['sometimes', 'boolean'];
        $rules['sort_order'] = ['nullable', 'integer', 'between:0,65535'];
        $rules['confirm_category_change'] = ['sometimes', 'boolean'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $group = $this->current();

            if ($validator->errors()->isNotEmpty() || ! $group instanceof OptionGroup) {
                return;
            }

            $items = $group->items()->count();

            if ($items > 0 && $this->input('category') !== $group->category->value && ! $this->boolean('confirm_category_change')) {
                $validator->errors()->add('category', __('Changing the category also changes the category of :count items of the group; confirm the change.', ['count' => $items]));
            }

            if ($group->selection === OptionSelection::Multiple && $this->input('selection') === OptionSelection::Single->value) {
                $broken = $this->trimsThatBreakTheRule($group);

                if ($broken !== []) {
                    $validator->errors()->add('selection', __(':count trims do not have exactly one standard item of this group (e.g. :example). Fix them in the equipment matrix first.', [
                        'count' => count($broken),
                        'example' => $broken[0],
                    ]));
                }
            }

            if ($group->uses_swatch && ! $this->boolean('uses_swatch') && ($swatches = $group->items()->whereNotNull('swatch_hex')->count()) > 0) {
                $validator->errors()->add('uses_swatch', __(':count items have a color swatch; remove the swatches first.', ['count' => $swatches]));
            }
        });
    }

    /**
     * Trims whose entries of the group would break the "exactly one standard item" rule if the
     * group became single-choice.
     *
     * @return list<string> "Model / Trim" labels
     */
    private function trimsThatBreakTheRule(OptionGroup $group): array
    {
        $broken = [];

        Trim::query()
            ->with('carModel')
            ->whereHas('trimEquipment', fn ($rows) => $rows->whereHas('equipmentItem', fn ($item) => $item->where('group_id', $group->id)))
            ->get()
            ->each(function (Trim $trim) use ($group, &$broken) {
                if (OptionGroupRule::problemsFor($group, $trim, asSingle: true) !== []) {
                    $broken[] = $trim->carModel->name.' / '.$trim->name;
                }
            });

        return $broken;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return parent::attributes() + [
            'category' => __('catalog.attr.category'),
            'selection' => __('catalog.attr.selection'),
            'uses_swatch' => __('catalog.attr.uses_swatch'),
        ];
    }
}
