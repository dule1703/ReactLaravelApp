<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Enums\DriveType;
use App\Enums\TransmissionType;
use App\Models\Transmission;
use Closure;
use Illuminate\Validation\Rule;

class TransmissionRequest extends CatalogRequest
{
    protected function modelClass(): string
    {
        return Transmission::class;
    }

    protected function routeParameter(): string
    {
        return 'transmission';
    }

    public function rules(): array
    {
        $rules = $this->baseRules();
        $rules['type'] = ['required', Rule::enum(TransmissionType::class)];
        $rules['drive'] = ['required', Rule::enum(DriveType::class)];
        $rules['name'][] = function (string $attribute, mixed $value, Closure $fail) {
            $taken = $this->nameTaken((string) $value, fn ($query) => $query
                ->where('type', $this->input('type'))
                ->where('drive', $this->input('drive')));

            if ($taken) {
                $fail(__('A transmission with this name, type and drive already exists.'));
            }
        };

        return $rules;
    }
}
