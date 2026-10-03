<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use App\Support\Money;
use App\Support\Vat;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A single price typed as net OR gross. The server parses the string and derives the net price
 * itself; it never accepts a net value computed by the browser.
 */
abstract class PriceInputRequest extends FormRequest
{
    /** Smallest allowed net price in cents (1 for versions, 0 for a free optional extra). */
    abstract protected function minNetCents(): int;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['net', 'gross'])],
            'amount' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail) {
                $cents = Money::parseEuros((string) $value);

                if ($cents === null) {
                    $fail(__('The amount is not valid.'));
                } elseif ($cents > Money::MAX_PRICE_CENTS) {
                    $fail(__('The amount is too large.'));
                } elseif ($this->netFrom($cents) < $this->minNetCents()) {
                    $fail(__('The price must be greater than zero.'));
                }
            }],
        ];
    }

    /**
     * The net price in cents derived on the server from the typed amount and the current rate.
     */
    public function netCents(): int
    {
        return $this->netFrom((int) Money::parseEuros((string) $this->input('amount')));
    }

    private function netFrom(int $cents): int
    {
        return $this->input('mode') === 'gross'
            ? Vat::netFromGross($cents, Setting::vatRateBp())
            : $cents;
    }
}
