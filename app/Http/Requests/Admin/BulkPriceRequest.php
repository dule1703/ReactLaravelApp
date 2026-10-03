<?php

namespace App\Http\Requests\Admin;

use App\Models\Version;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parameters of a bulk price change (preview and apply use the same rules). Hard limits:
 * +-50% or +-100,000 EUR; anything above 10% per item additionally needs a confirmation.
 */
class BulkPriceRequest extends FormRequest
{
    public const MAX_PERCENT_BP = 5000;

    public const MAX_AMOUNT_CENTS = 10_000_000;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Version::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'car_model_id' => ['required', 'integer', 'exists:car_models,id'],
            'trim_id' => ['nullable', 'integer', Rule::exists('trims', 'id')->where('car_model_id', $this->input('car_model_id'))],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['string', Rule::in(['versions', 'equipment'])],
            'change_type' => ['required', Rule::in(['percent', 'amount'])],
            'amount_mode' => ['required_if:change_type,amount', 'nullable', Rule::in(['net', 'gross'])],
            'value' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                if ($this->input('change_type') === 'percent') {
                    $bp = Money::parsePercentBp((string) $value, signed: true);

                    if ($bp === null || abs($bp) > self::MAX_PERCENT_BP) {
                        $fail(__('The percentage must be between -50 and 50 with at most two decimals.'));
                    }

                    return;
                }

                $cents = Money::parseEuros((string) $value, signed: true);

                if ($cents === null || abs($cents) > self::MAX_AMOUNT_CENTS) {
                    $fail(__('The amount must be between -100.000 and 100.000 EUR.'));
                }
            }],
            'confirm_large' => ['sometimes', 'boolean'],
            'token' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array{model_id: int, trim_id: ?int, targets: list<string>, type: string, value: int, mode: string}
     */
    public function params(): array
    {
        $percent = $this->input('change_type') === 'percent';
        $value = (string) $this->input('value');

        return [
            'model_id' => (int) $this->input('car_model_id'),
            'trim_id' => $this->filled('trim_id') ? (int) $this->input('trim_id') : null,
            'targets' => array_values($this->input('targets')),
            'type' => $this->input('change_type'),
            'value' => $percent
                ? (int) Money::parsePercentBp($value, signed: true)
                : (int) Money::parseEuros($value, signed: true),
            'mode' => $percent ? 'net' : (string) $this->input('amount_mode'),
        ];
    }
}
