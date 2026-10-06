<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateIssuerProfileRequest;
use App\Models\IssuerProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The details of the dealer that issues the offers (5.3). Admin only. A change applies to the offers
 * made AFTER it: each offer keeps the copy it was made with.
 */
class IssuerProfileController extends Controller
{
    public function edit(): Response
    {
        $profile = IssuerProfile::current();
        Gate::authorize('view', $profile);

        return Inertia::render('Admin/Issuer', [
            'issuer' => [
                'name' => $profile->name ?? '',
                'address' => $profile->address ?? '',
                'postal_code' => $profile->postal_code ?? '',
                'city' => $profile->city ?? '',
                'pib' => $profile->pib ?? '',
                'phone' => $profile->phone ?? '',
                'email' => $profile->email ?? '',
            ],
        ]);
    }

    public function update(UpdateIssuerProfileRequest $request): RedirectResponse
    {
        IssuerProfile::current()->fill($request->validated())->save();

        return redirect()->route('issuer.edit')->with('success', __('The details of the offer issuer were saved.'));
    }
}
