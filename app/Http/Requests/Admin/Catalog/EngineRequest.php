<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Enums\FuelType;
use App\Models\Engine;
use Closure;
use Illuminate\Validation\Rule;

class EngineRequest extends CatalogRequest
{
    protected function modelClass(): string
    {
        return Engine::class;
    }

    protected function routeParameter(): string
    {
        return 'engine';
    }

    public function rules(): array
    {
        $rules = $this->baseRules();
        $rules['fuel_type'] = ['required', Rule::enum(FuelType::class)];
        $rules['power_kw'] = ['required', 'integer', 'between:20,1000'];
        $rules['name'][] = function (string $attribute, mixed $value, Closure $fail) {
            $taken = $this->nameTaken((string) $value, fn ($query) => $query
                ->where('fuel_type', $this->input('fuel_type'))
                ->where('power_kw', $this->input('power_kw')));

            if ($taken) {
                $fail(__('An engine with this name, fuel and power already exists.'));
            }
        };

        return $rules;
    }
}
