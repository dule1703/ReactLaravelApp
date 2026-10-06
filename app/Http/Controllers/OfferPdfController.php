<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Services\OfferPdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The PDF of an offer, inline (opens in the browser; printing is the browser's print of this same
 * PDF). Same rule as the page of the offer: an admin any offer, a client only their own, someone
 * else's is "not found". Reading writes nothing; the download/print log entries come in 5.2.
 */
class OfferPdfController extends Controller
{
    public function __invoke(Offer $offer, OfferPdf $pdf): Response
    {
        Gate::authorize('view', $offer);

        return response($pdf->render($offer), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.OfferPdf::filename($offer).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
