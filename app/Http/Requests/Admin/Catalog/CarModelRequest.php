<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\CarModel;
use Closure;

/**
 * Car model form: name, order, categories and an optional image. The file type is decided
 * from the real content (the `mimes` rule uses the detected MIME type), never from the name or
 * extension the client sends, and SVG is not an allowed type.
 */
class CarModelRequest extends CatalogRequest
{
    public const IMAGE_MAX_KB = 2048;

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

        $rules['image'] = [
            'nullable',
            'file',
            'mimes:jpg,jpeg,png,webp',
            'max:'.self::IMAGE_MAX_KB,
            'dimensions:min_width=400,min_height=250,max_width=4000,max_height=4000',
        ];
        $rules['remove_image'] = ['sometimes', 'boolean'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tooLarge = __('The image is larger than the allowed 2 MB.');

        return [
            'image.max' => $tooLarge,
            'image.uploaded' => $tooLarge,
            'image.mimes' => __('The image must be a JPG, PNG or WEBP file.'),
            'image.dimensions' => __('The image must be from 400x250 to 4000x4000 pixels.'),
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + [
            'image' => __('catalog.attr.image'),
            'category_ids' => __('catalog.attr.category_ids'),
        ];
    }
}
