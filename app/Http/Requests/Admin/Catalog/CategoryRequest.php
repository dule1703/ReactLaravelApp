<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\Category;
use Closure;

class CategoryRequest extends CatalogRequest
{
    protected function modelClass(): string
    {
        return Category::class;
    }

    protected function routeParameter(): string
    {
        return 'category';
    }

    public function rules(): array
    {
        $rules = $this->baseRules();
        $rules['sort_order'] = ['nullable', 'integer', 'between:0,65535'];
        $rules['name'][] = function (string $attribute, mixed $value, Closure $fail) {
            if ($this->nameTaken((string) $value, fn ($query) => $query)) {
                $fail(__('A category with this name already exists.'));
            }
        };

        return $rules;
    }
}
