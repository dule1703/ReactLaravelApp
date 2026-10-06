<?php

namespace App\Http\Controllers;

use App\Http\Requests\OfferIndexRequest;
use App\Http\Requests\StoreOfferRequest;
use App\Models\CarModel;
use App\Models\ClientProfile;
use App\Models\Offer;
use App\Models\User;
use App\Services\OfferCreator;
use App\Services\OfferTotalMismatchException;
use App\Support\Like;
use App\Support\OfferCalculator;
use App\Support\OfferClientRules;
use App\Support\OfferPresenter;
use App\Support\VatRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Offers: the list and the page of one offer (both roles, see OfferPolicy) and the offer
 * configurator of a client. The page only gets what is needed to start (models that
 * can be offered, the VAT rate, the limits, which profile fields are missing); everything else is
 * loaded as JSON by OfferCatalogController. Saving is JSON too, not an Inertia visit: Inertia
 * reserves the status 409 for an asset version change, and this endpoint answers 409 when the
 * totals changed.
 */
class OfferController extends Controller
{
    /** Names of the profile fields for the message to the admin (keys of lang/sr_Latn.json). */
    private const FIELD_LABELS = [
        'full_name' => 'Full name or company name',
        'address' => 'Address',
        'postal_code' => 'Postal code',
        'city' => 'City',
        'country' => 'Country',
        'pib' => 'PIB',
    ];

    /** Columns of the snapshot the list search looks at; the PIB only for the admin. */
    private const SEARCH_COLUMNS = ['offers.number', 'offers.client_name', 'offers.note'];

    /**
     * The list is narrowed by the query to the offers of the signed-in client (an admin sees all),
     * never filtered after loading.
     */
    public function index(OfferIndexRequest $request): Response
    {
        $user = $request->user();
        $admin = $user->isAdmin();
        $filters = $request->validated();
        $term = trim($filters['q'] ?? '');
        $perPage = (int) ($filters['per_page'] ?? OfferIndexRequest::PER_PAGE_OPTIONS[0]);

        $offers = Offer::query()
            ->withCount('items')
            ->when(! $admin, fn (Builder $query) => $query->where('offers.user_id', $user->id))
            ->when($term !== '', fn (Builder $query) => $this->search($query, $term, $admin))
            ->orderByDesc('offers.offer_date')
            ->orderByDesc('offers.id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Offer $offer) => OfferPresenter::row($offer, $admin));

        return Inertia::render('Offers/Index', [
            'offers' => $offers,
            'filters' => ['q' => $term, 'per_page' => $perPage],
            'perPageOptions' => OfferIndexRequest::PER_PAGE_OPTIONS,
            'isAdmin' => $admin,
        ]);
    }

    public function show(Request $request, Offer $offer): Response
    {
        Gate::authorize('view', $offer);

        $admin = $request->user()->isAdmin();
        $offer->load($admin ? ['items.options', 'user.clientProfile:id,user_id'] : ['items.options']);

        return Inertia::render('Offers/Show', ['offer' => OfferPresenter::detail($offer, $admin)]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Offer::class);

        $models = CarModel::query()
            ->whereHas('versions', fn ($versions) => $versions->available())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (CarModel $model) => [
                'id' => $model->id,
                'name' => $model->name,
                'image_url' => $model->imageUrl(),
            ])
            ->values();

        return Inertia::render('Offers/Create', [
            'models' => $models,
            'vatRateBp' => VatRate::current(),
            // Only the NAMES of the profile fields that are missing, never profile data. An admin has no
            // profile: they choose a client, whose profile is checked when the offer is saved.
            'profileMissing' => $request->user()->isClient() ? OfferClientRules::missing($request->user()->profile()) : [],
            'isAdmin' => $request->user()->isAdmin(),
            'limits' => [
                'maxItems' => OfferCalculator::MAX_ITEMS,
                'maxQuantity' => OfferCalculator::MAX_QUANTITY,
                'noteMax' => StoreOfferRequest::NOTE_MAX,
            ],
        ]);
    }

    public function store(StoreOfferRequest $request, OfferCreator $creator): JsonResponse
    {
        try {
            $offer = $creator->create(
                $this->owner($request),
                $request->validated('note'),
                $request->input('items'),
                [
                    'expected_total_net_cents' => $request->validated('expected_total_net_cents'),
                    'expected_total_gross_cents' => $request->validated('expected_total_gross_cents'),
                ],
            );
        } catch (OfferTotalMismatchException $e) {
            return response()->json([
                'message' => __('The prices have changed since you calculated the offer. Confirm the new amounts to save it.'),
                'totals' => [
                    'total_net_cents' => $e->totalNetCents,
                    'vat_cents' => $e->vatCents,
                    'total_gross_cents' => $e->totalGrossCents,
                ],
                'vat_rate_bp' => $e->vatRateBp,
            ], 409)->header('Cache-Control', 'no-store');
        }

        $request->session()->flash('success', __('Offer :number saved.', ['number' => $offer->number]));

        return response()->json([
            'id' => $offer->id,
            'number' => $offer->number,
            'redirect' => route('offers.show', $offer),
        ], 201);
    }

    /**
     * Who the offer is for. A client: always themselves (a `client_id` in the request is never read).
     * An admin: the chosen client (the id of a client PROFILE), never the admin: the account must be a
     * client and the profile must be complete (the printed offer needs it), else a 422 that names
     * the missing fields; nothing is written.
     */
    private function owner(StoreOfferRequest $request): User
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            return $user;
        }

        $profile = ClientProfile::query()->with('user')->find($request->validated('client_id'));

        if ($profile === null || $profile->user === null || ! $profile->user->isClient()) {
            throw ValidationException::withMessages(['client_id' => __('Choose an existing client.')]);
        }

        $missing = OfferClientRules::missing($profile);

        if ($missing !== []) {
            throw ValidationException::withMessages(['client_id' => __('The profile of this client is incomplete: :fields. Complete it before making the offer.', [
                'fields' => implode(', ', array_map(fn (string $field) => __(self::FIELD_LABELS[$field]), $missing)),
            ])]);
        }

        return $profile->user;
    }

    private function search(Builder $query, string $term, bool $admin): Builder
    {
        $like = Like::contains($term);
        $columns = $admin ? [...self::SEARCH_COLUMNS, 'offers.client_pib'] : self::SEARCH_COLUMNS;

        return $query->where(function (Builder $query) use ($columns, $like) {
            foreach ($columns as $column) {
                // "!" is the escape character: the same on MySQL and SQLite (a backslash is not).
                $query->orWhereRaw("$column like ? escape '!'", [$like]);
            }
        });
    }
}
