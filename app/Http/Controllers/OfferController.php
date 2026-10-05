<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOfferRequest;
use App\Models\CarModel;
use App\Models\Offer;
use App\Services\OfferCreator;
use App\Services\OfferTotalMismatchException;
use App\Support\OfferCalculator;
use App\Support\OfferClientRules;
use App\Support\VatRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The offer configurator of a client. The page only gets what is needed to start (models that
 * can be offered, the VAT rate, the limits, which profile fields are missing); everything else is
 * loaded as JSON by OfferCatalogController. Saving is JSON too, not an Inertia visit: Inertia
 * reserves the status 409 for an asset version change, and this endpoint answers 409 when the
 * totals changed.
 */
class OfferController extends Controller
{
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
            // Only the NAMES of the profile fields that are missing, never profile data.
            'profileMissing' => OfferClientRules::missing($request->user()->profile()),
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
                $request->user(),
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
            // Back to the configurator; the list of offers (4.6) takes over when it exists.
            'redirect' => route('offers.create'),
        ], 201);
    }
}
