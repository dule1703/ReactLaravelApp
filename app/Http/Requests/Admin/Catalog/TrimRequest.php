<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\Trim;
use Closure;

/**
 * The car model of a trim is chosen on create and never changes afterwards (versions depend
 * on it), so it is validated only for POST and ignored on update.
 */
class TrimRequest extends CatalogRequest
{
    protected function modelClass(): string
    {
        return Trim::class;
    }

    protected function routeParameter(): string
    {
        return 'trim';
    }

    public function rules(): array
    {
        $rules = $this->baseRules();
        $rules['sort_order'] = ['nullable', 'integer', 'between:0,65535'];
        $rules['name'][] = function (string $attribute, mixed $value, Closure $fail) {
            $modelId = $this->current()?->car_model_id ?? $this->input('car_model_id');

            if ($this->nameTaken((string) $value, fn ($query) => $query->where('car_model_id', $modelId))) {
                $fail(__('This car model already has a trim with this name.'));
            }
        };

        if ($this->current() === null) {
            $rules['car_model_id'] = ['required', 'integer', 'exists:car_models,id'];
        }

        return $rules;
    }
}
