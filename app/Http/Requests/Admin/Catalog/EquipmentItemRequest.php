<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Enums\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Support\ImageRules;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Equipment item form (create and update). Cross-field rules, each with its own message:
 *  - a group fixes the category (it must equal the category of the group);
 *  - a color swatch only for items of a group that uses swatches;
 *  - the group of an item that is already on some trim cannot change (it could break "exactly one
 *    standard item"); such changes belong to the matrix (3.11);
 *  - an item that is the standard item of a single-choice group on some trim cannot be deactivated.
 */
class EquipmentItemRequest extends CatalogRequest
{
    protected function modelClass(): string
    {
        return EquipmentItem::class;
    }

    protected function routeParameter(): string
    {
        return 'equipmentItem';
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // A multipart form sends empty strings for empty fields.
        $empty = [];
        foreach (['group_id', 'swatch_hex', 'sort_order'] as $field) {
            if ($this->input($field) === '') {
                $empty[$field] = null;
            }
        }
        $this->merge($empty);
    }

    public function rules(): array
    {
        $rules = $this->baseRules();
        $rules['name'] = ['required', 'string', 'max:150', 'regex:/^[^\p{C}]+$/u', function (string $attribute, mixed $value, Closure $fail) {
            if ($this->nameTaken((string) $value, fn ($query) => $query)) {
                $fail(__('An equipment item with this name already exists.'));
            }
        }];
        $rules['category'] = ['required', Rule::enum(EquipmentCategory::class)];
        $rules['group_id'] = ['nullable', 'integer', 'exists:option_groups,id'];
        $rules['swatch_hex'] = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $rules['sort_order'] = ['nullable', 'integer', 'between:0,65535'];
        $rules['image'] = ImageRules::rules();
        $rules['remove_image'] = ['sometimes', 'boolean'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $item = $this->current();
            $group = $this->filled('group_id') ? OptionGroup::find($this->input('group_id')) : null;

            if ($group !== null && $this->input('category') !== $group->category->value) {
                $validator->errors()->add('category', __('The category must be the category of the group (:category).', ['category' => __('equipment.category.'.$group->category->value)]));
            }

            if ($this->filled('swatch_hex') && ($group === null || ! $group->uses_swatch)) {
                $validator->errors()->add('swatch_hex', __('A color swatch is allowed only for items of a group with color swatches.'));
            }

            if (! $item instanceof EquipmentItem) {
                return;
            }

            $lines = $item->trimEquipment()->count();
            if ($lines > 0 && (int) $item->group_id !== (int) $group?->id) {
                $validator->errors()->add('group_id', __('The item is on :count trims; its group cannot be changed. Deactivate it or change it in the equipment matrix.', ['count' => $lines]));
            }

            if ($item->is_active && $this->has('is_active') && ! $this->boolean('is_active') && ($standard = $item->standardLinesInSingleGroup()) > 0) {
                $validator->errors()->add('is_active', __('The item is the standard item of a single-choice group on :count trims; replace it in the equipment matrix before deactivating it.', ['count' => $standard]));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ImageRules::messages('image') + [
            'swatch_hex.regex' => __('The swatch must be a color in the form #RRGGBB.'),
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + [
            'category' => __('catalog.attr.category'),
            'group_id' => __('catalog.attr.group_id'),
            'swatch_hex' => __('catalog.attr.swatch_hex'),
            'image' => __('catalog.attr.image'),
        ];
    }
}
