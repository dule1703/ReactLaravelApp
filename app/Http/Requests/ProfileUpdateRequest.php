<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /** A person edits their own account (UserPolicy::update: the owner or an admin; the route has no id). */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->user()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }

    /**
     * The form calls this field "Ime i prezime"; the global attribute 'name' ("naziv") is for the catalog.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => mb_strtolower(__('Name'))];
    }
}
