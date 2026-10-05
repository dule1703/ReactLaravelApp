<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClientIndexRequest;
use App\Http\Requests\Admin\RevealSensitiveRequest;
use App\Http\Requests\UpdateClientProfileRequest;
use App\Models\ClientProfile;
use App\Services\ActivityLogger;
use App\Support\Jmbg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin management of client profiles. Props never carry the full JMBG or its hash; the full
 * JMBG/PIB leave the server only through reveal() (logged) and the PIB on the edit page (logged).
 */
class ClientController extends Controller
{
    private const SEARCH_COLUMNS = [
        'client_profiles.full_name',
        'users.email',
        'client_profiles.pib',
        'client_profiles.city',
        'client_profiles.address',
        'client_profiles.postal_code',
    ];

    public function index(ClientIndexRequest $request): Response
    {
        $filters = $request->validated();
        $term = trim($filters['q'] ?? '');
        $perPage = (int) ($filters['per_page'] ?? ClientIndexRequest::PER_PAGE_OPTIONS[0]);

        $clients = ClientProfile::query()
            ->join('users', 'users.id', '=', 'client_profiles.user_id')
            ->select('client_profiles.*')
            ->with('user')
            ->when($term !== '', fn (Builder $query) => $this->search($query, $term))
            ->orderByDesc('users.created_at')
            ->orderByDesc('client_profiles.id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (ClientProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->full_name ?? $profile->user->name,
                'email' => $profile->user->email,
                'type' => $profile->type->value,
                'jmbg_masked' => $profile->maskedJmbg(),
                'pib_masked' => $profile->maskedPib(),
                'address' => $profile->address,
                'postal_code' => $profile->postal_code,
                'city' => $profile->city,
                'country' => __("country.$profile->country"),
                'registered_at' => $profile->user->created_at->timezone(config('app.timezone'))->format('d.m.Y'),
            ]);

        return Inertia::render('Admin/Clients/Index', [
            'clients' => $clients,
            'filters' => ['q' => $term, 'per_page' => $perPage],
            'perPageOptions' => ClientIndexRequest::PER_PAGE_OPTIONS,
        ]);
    }

    public function edit(ClientProfile $clientProfile): Response
    {
        Gate::authorize('update', $clientProfile);

        // The edit form shows the PIB in full, so opening the page counts as viewing it.
        if (filled($clientProfile->pib)) {
            $this->logSensitiveView($clientProfile, 'pib');
        }

        return Inertia::render('Admin/Clients/Edit', [
            'client' => [
                'id' => $clientProfile->id,
                'email' => $clientProfile->user->email,
            ],
            'profile' => [
                'type' => $clientProfile->type->value,
                'full_name' => $clientProfile->full_name,
                'jmbg_masked' => $clientProfile->maskedJmbg(),
                'pib' => $clientProfile->pib,
                'address' => $clientProfile->address,
                'postal_code' => $clientProfile->postal_code,
                'city' => $clientProfile->city,
                'country' => $clientProfile->country,
            ],
            'countries' => collect(config('countries.codes'))
                ->map(fn (string $code) => ['code' => $code, 'name' => __("country.$code")])
                ->all(),
        ]);
    }

    public function update(UpdateClientProfileRequest $request, ClientProfile $clientProfile): RedirectResponse
    {
        try {
            // Through the model only, so jmbg_hash stays in sync with jmbg.
            $clientProfile->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['jmbg' => __('The JMBG is not valid.')]);
        }

        return redirect()->route('clients.index')->with('success', __('Profile saved.'));
    }

    /**
     * Returns one full sensitive value as JSON, never cached, and logs the view (field name only).
     */
    public function reveal(RevealSensitiveRequest $request, ClientProfile $clientProfile): JsonResponse
    {
        $field = $request->validated('field');
        $value = $clientProfile->{$field};

        if (filled($value)) {
            $this->logSensitiveView($clientProfile, $field);
        }

        return response()->json(['value' => $value])
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroyJmbg(ClientProfile $clientProfile): RedirectResponse
    {
        Gate::authorize('deleteJmbg', $clientProfile);

        // Model update, so the hash is cleared too; logged as client_profile.jmbg_deleted.
        $clientProfile->update(['jmbg' => null]);

        return back()->with('success', __('JMBG deleted.'));
    }

    public function destroy(ClientProfile $clientProfile): RedirectResponse
    {
        Gate::authorize('delete', $clientProfile);

        // Offers are snapshot documents of the client: a client with offers is not deleted (the
        // foreign key offers.user_id RESTRICT is the safety net for a race).
        $offers = $clientProfile->user->offers()->count();

        if ($offers > 0) {
            return back()->with('error', __('The client has offers (:count) and cannot be deleted.', ['count' => $offers]));
        }

        // Explicitly, profile first, so both deletions reach the activity log (a database
        // cascade would not fire model events). The log itself is never touched.
        try {
            DB::transaction(function () use ($clientProfile) {
                $user = $clientProfile->user;
                $clientProfile->delete();
                $user->delete();
            });
        } catch (QueryException) {
            return back()->with('error', __('The client has offers and cannot be deleted.'));
        }

        return redirect()->route('clients.index')->with('success', __('Client deleted.'));
    }

    /**
     * Free text over the listed columns (LIKE with a safe escape), plus an exact JMBG match
     * through the keyed hash when the input is a 13-digit number.
     */
    private function search(Builder $query, string $term): Builder
    {
        // "!" is the LIKE escape character (see below): escape it, "%" and "_" in the input.
        $like = '%'.preg_replace('/[!%_]/', '!$0', $term).'%';
        $jmbg = preg_replace('/[\s-]/', '', $term);
        $hash = preg_match('/^\d{13}$/', $jmbg) ? Jmbg::hash($jmbg) : null;

        return $query->where(function (Builder $query) use ($like, $hash) {
            foreach (self::SEARCH_COLUMNS as $column) {
                // "!" is the escape character: unlike a backslash it behaves the same on
                // MySQL and SQLite.
                $query->orWhereRaw("$column like ? escape '!'", [$like]);
            }

            if ($hash !== null) {
                $query->orWhere('client_profiles.jmbg_hash', $hash);
            }
        });
    }

    private function logSensitiveView(ClientProfile $profile, string $field): void
    {
        // Only the field name is recorded, never the value.
        app(ActivityLogger::class)->log('client_profile.sensitive_viewed', $profile, description: $field);
    }
}
