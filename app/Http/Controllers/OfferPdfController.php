<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Services\ActivityLogger;
use App\Services\OfferPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The PDF of an offer. Same rule as the page of the offer: an admin any offer, a client only their
 * own, someone else's is "not found".
 *
 * Printing is the browser's own action on this PDF opened inline in a new tab. The server cannot
 * know whether anyone really printed, so it records only what it sees, AFTER the PDF was rendered
 * (a failure, someone else's offer and a guest leave no entry):
 *  - inline                  -> offer.pdf_opened     ("opened for printing", never "printed")
 *  - ?download=1 (attachment) -> offer.pdf_downloaded
 * The entry has the signed-in user as the actor and the offer as the subject (id and number in
 * its label); no changes, no description, no client data. Every request is one entry.
 */
class OfferPdfController extends Controller
{
    public function __invoke(Request $request, Offer $offer, OfferPdf $pdf, ActivityLogger $logger): Response
    {
        Gate::authorize('view', $offer);

        $download = $request->boolean('download');
        $content = $pdf->render($offer);

        $logger->log($download ? 'offer.pdf_downloaded' : 'offer.pdf_opened', $offer);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.OfferPdf::filename($offer).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
