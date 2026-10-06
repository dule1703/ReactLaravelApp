<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Editing the note of an offer (4.6c). The Policy runs before validation, so someone else's offer is
 * "not found" and a withdrawn one is forbidden whatever the input is. Same limit as when the offer
 * is made; an empty note is stored as null.
 */
class UpdateOfferNoteRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('offer'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['note' => ['present', 'nullable', 'string', 'max:'.StoreOfferRequest::NOTE_MAX]];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note' => __('Note')];
    }
}
