<?php

namespace App\Http\Controllers;

use App\Enums\OptionSelection;
use App\Enums\UserRole;
use App\Models\CarModel;
use App\Models\ClientProfile;
use App\Models\EquipmentItem;
use App\Models\Offer;
use App\Models\Version;
use App\Support\Like;
use App\Support\OfferClientRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only JSON of what the configurator may show. Only what Version::available() and
 * EquipmentItem::scopeOfferable() allow; amounts are NET cents straight from the catalog (the
 * formatting and the gross values are the page's job). Nothing here reads a price from the request.
 */
class OfferCatalogController extends Controller
{
    /** Trims of a model with their available versions. */
    public function versions(CarModel $carModel): JsonResponse
    {
        Gate::authorize('create', Offer::class);
        abort_unless($carModel->is_active, 404);

        $versions = Version::available()
            ->with(['trim', 'engine', 'transmission'])
            ->whereHas('trim', fn ($trim) => $trim->where('car_model_id', $carModel->id))
            ->get()
            ->sortBy([
                fn (Version $a, Version $b) => [$a->trim->sort_order, $a->trim_id] <=> [$b->trim->sort_order, $b->trim_id],
                fn (Version $a, Version $b) => $a->base_price_cents <=> $b->base_price_cents,
                fn (Version $a, Version $b) => $a->id <=> $b->id,
            ]);

        $trims = $versions->groupBy('trim_id')->map(fn ($group) => [
            'id' => $group->first()->trim->id,
            'name' => $group->first()->trim->name,
            'versions' => $group->map(fn (Version $version) => [
                'id' => $version->id,
                'price_cents' => $version->base_price_cents,
                'engine' => [
                    'name' => $version->engine->name,
                    'fuel_type' => $version->engine->fuel_type->value,
                    'power_kw' => $version->engine->power_kw,
                ],
                'transmission' => [
                    'name' => $version->transmission->name,
                    'type' => $version->transmission->type->value,
                    'drive' => $version->transmission->drive->value,
                ],
            ])->values(),
        ])->values();

        return $this->json(['trims' => $trims]);
    }

    /** One version: standard equipment (to show), extras to choose, grouped. */
    public function version(Version $version): JsonResponse
    {
        Gate::authorize('create', Offer::class);
        abort_unless(Version::available()->whereKey($version->id)->exists(), 404);

        $version->load(['trim.carModel', 'engine', 'transmission']);

        $standard = $version->standardEquipment()->get();
        $extras = $version->offerableExtras()->get();

        $defaults = $standard
            ->filter(fn (EquipmentItem $item) => $item->group?->selection === OptionSelection::Single)
            ->keyBy('group_id');

        $groups = $extras
            ->filter(fn (EquipmentItem $item) => $item->group_id !== null)
            ->groupBy('group_id')
            ->map(function ($items) use ($defaults) {
                $group = $items->first()->group;
                $default = $defaults->get($group->id);

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'category' => $group->category->value,
                    'selection' => $group->selection->value,
                    'uses_swatch' => $group->uses_swatch,
                    // Only a single-choice group has a default (its standard item, in the price).
                    'default' => $group->selection === OptionSelection::Single && $default
                        ? ['id' => $default->id, 'name' => $default->name, 'swatch_hex' => $default->swatch_hex]
                        : null,
                    'options' => $items->map(fn (EquipmentItem $item) => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'price_cents' => (int) $item->extra_price_cents,
                        'swatch_hex' => $item->swatch_hex,
                    ])->values(),
                ];
            })->values();

        return $this->json([
            'version' => [
                'id' => $version->id,
                'price_cents' => $version->base_price_cents,
                'model' => $version->trim->carModel->name,
                'trim' => $version->trim->name,
                'engine' => [
                    'name' => $version->engine->name,
                    'fuel_type' => $version->engine->fuel_type->value,
                    'power_kw' => $version->engine->power_kw,
                ],
                'transmission' => [
                    'name' => $version->transmission->name,
                    'type' => $version->transmission->type->value,
                    'drive' => $version->transmission->drive->value,
                ],
            ],
            'standard' => $standard->map(fn (EquipmentItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category->value,
                'group_name' => $item->group?->name,
            ])->values(),
            'groups' => $groups,
            'extras' => $extras->filter(fn (EquipmentItem $item) => $item->group_id === null)->map(fn (EquipmentItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category->value,
                'price_cents' => (int) $item->extra_price_cents,
            ])->values(),
        ]);
    }

    /**
     * The admin searches the clients to make an offer for one of them: the name (from the profile) and
     * the email of an account with the role client, at least 2 characters, at most 10 results. Only
     * what tells two clients apart is returned (id of the PROFILE, name, city, email) and whether the
     * profile is complete enough for an offer; never a JMBG, a PIB or an address.
     */
    public function clients(Request $request): JsonResponse
    {
        Gate::authorize('chooseClient', Offer::class);

        $term = trim($request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $like = Like::contains($term);

        $clients = ClientProfile::query()
            ->join('users', 'users.id', '=', 'client_profiles.user_id')
            ->where('users.role', UserRole::Client->value)
            ->where(fn ($query) => $query
                ->whereRaw("client_profiles.full_name like ? escape '!'", [$like])
                ->orWhereRaw("users.email like ? escape '!'", [$like]))
            ->orderBy('client_profiles.full_name')
            ->orderBy('client_profiles.id')
            ->limit(10)
            ->select('client_profiles.*', 'users.email as account_email', 'users.name as account_name')
            ->get()
            ->map(fn (ClientProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->full_name ?? $profile->account_name,
                'city' => $profile->city,
                'email' => $profile->account_email,
                'complete' => OfferClientRules::missing($profile) === [],
            ])
            ->values();

        return $this->json(['clients' => $clients]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): JsonResponse
    {
        // Prices change in the admin: never serve a stale copy.
        return response()->json($data)->header('Cache-Control', 'no-store');
    }
}
