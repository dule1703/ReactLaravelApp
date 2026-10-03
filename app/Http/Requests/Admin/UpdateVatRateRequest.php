<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use App\Support\Money;
use App\Support\Vat;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVatRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', Setting::query()->firstOrNew(['key' => Setting::VAT_RATE_BP])) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rate' => ['required', 'string', 'max:10', function (string $attribute, mixed $value, Closure $fail) {
                $bp = Money::parsePercentBp((string) $value);

                if ($bp === null || $bp < Vat::RATE_MIN_BP || $bp > Vat::RATE_MAX_BP) {
                    $fail(__('The VAT rate must be a percentage from 0 to 100 with at most two decimals.'));
                }
            }],
        ];
    }

    public function rateBp(): int
    {
        return (int) Money::parsePercentBp((string) $this->input('rate'));
    }
}
