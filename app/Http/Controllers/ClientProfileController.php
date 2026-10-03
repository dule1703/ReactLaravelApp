<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateClientProfileRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in client's own profile. There is deliberately no profile id in the route:
 * the profile is always `$request->user()->profile()`.
 */
class ClientProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $profile = $request->user()->profile();

        // Explicit props: the full JMBG and its hash never leave the server, only a mask.
        return Inertia::render('ClientProfile/Edit', [
            'profile' => [
                'type' => $profile->type->value,
                'full_name' => $profile->full_name,
                'jmbg_masked' => $profile->maskedJmbg(),
                'pib' => $profile->pib,
                'address' => $profile->address,
                'postal_code' => $profile->postal_code,
                'city' => $profile->city,
                'country' => $profile->country,
            ],
            'countries' => collect(config('countries.codes'))
                ->map(fn (string $code) => ['code' => $code, 'name' => __("country.$code")])
                ->all(),
        ]);
    }

    public function update(UpdateClientProfileRequest $request): RedirectResponse
    {
        try {
            // Through the model only, so jmbg_hash stays in sync with jmbg.
            $request->user()->profile()->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            // Race: another request stored the same JMBG after validation passed.
            throw ValidationException::withMessages(['jmbg' => __('The JMBG is not valid.')]);
        }

        return redirect()->route('client-profile.edit')->with('success', __('Profile saved.'));
    }
}
