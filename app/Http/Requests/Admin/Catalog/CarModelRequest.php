<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\CarModel;
use Closure;

class CarModelRequest extends CatalogRequest
{
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

        return $rules;
    }
}
