<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\EquipmentItem;
use App\Support\ImageRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Image and color swatch of an equipment item (the CRUD screen that uses this arrives in 3.10).
 * Same image rules as car models; the swatch must be #RRGGBB.
 */
class EquipmentItemImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('equipmentItem');

        return $item instanceof EquipmentItem
            ? ($this->user()?->can('update', $item) ?? false)
            : ($this->user()?->can('create', EquipmentItem::class) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image' => ImageRules::rules(),
            'remove_image' => ['sometimes', 'boolean'],
            'swatch_hex' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
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
}
