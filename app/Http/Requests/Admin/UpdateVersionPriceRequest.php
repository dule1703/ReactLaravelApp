<?php

namespace App\Http\Requests\Admin;

class UpdateVersionPriceRequest extends PriceInputRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('version')) ?? false;
    }

    protected function minNetCents(): int
    {
        return 1;
    }
}
