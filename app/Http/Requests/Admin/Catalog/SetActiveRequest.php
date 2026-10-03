<?php

namespace App\Http\Requests\Admin\Catalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes the concrete row through its policy.
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
