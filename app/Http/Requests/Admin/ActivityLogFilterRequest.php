<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivityLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ActivityLog::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'action' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'ip' => ['nullable', 'string', 'max:45'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}
