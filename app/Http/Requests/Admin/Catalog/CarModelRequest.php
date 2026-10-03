<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\CarModel;
use App\Support\ImageRules;
use Closure;

/**
 * Car model form: name, order, categories and an optional image. The file type is decided
 * from the real content (the `mimes` rule uses the detected MIME type), never from the name or
 * extension the client sends, and SVG is not an allowed type.
 */
class CarModelRequest extends CatalogRequest
{
    public const IMAGE_MAX_KB = ImageRules::MAX_KB;

    protected function modelClass(): string
    {
        return CarModel::class;
    }

    protected function routeParameter(): string
    {
        return 'carModel';
    }

    public function rules(): array
    {
        $rules = $this->baseRules();
        $rules['name'][] = function (string $attribute, mixed $value, Closure $fail) {
            if ($this->nameTaken((string) $value, fn ($query) => $query)) {
                $fail(__('A car model with this name already exists.'));
            }
        };
        $rules['sort_order'] = ['nullable', 'integer', 'between:0,65535'];

        // The form always sends `sync_categories`; without it the categories are left alone
        // (an empty selection is not sent by browsers, so it cannot be told apart otherwise).
        $rules['sync_categories'] = ['sometimes', 'boolean'];
        $rules['category_ids'] = ['nullable', 'array'];
        $rules['category_ids.*'] = ['integer', 'distinct', 'exists:categories,id'];

        $rules['image'] = ImageRules::rules();
        $rules['remove_image'] = ['sometimes', 'boolean'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ImageRules::messages('image');
    }

    public function attributes(): array
    {
        return parent::attributes() + [
            'image' => __('catalog.attr.image'),
            'category_ids' => __('catalog.attr.category_ids'),
        ];
    }
}
