<?php

namespace App\Services;

use App\Models\Offer;
use App\Support\OfferPresenter;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Renders the PDF of an offer. The ONLY place that talks to dompdf, and the content comes only
 * from OfferPresenter (the snapshot stored in the offer): never the live client profile or the
 * catalog, never a JMBG.
 *
 * Safe by construction: no remote files (the logo is a data URI of our own public/images/logo.png),
 * no JavaScript, no PHP in the template, dompdf's `chroot` is its own work folder. The font is the
 * "DejaVu Sans" that ships with dompdf (it has ć č đ š ž, Latin Extended and the euro sign).
 * dompdf writes only a metrics cache and temp files, into storage/app/pdf (the folder is created
 * here; on the server storage is the shared symlink, so nothing has to be prepared by hand).
 * Reading is not logged: that is the job of the route that serves the file (5.2).
 */
class OfferPdf
{
    private const FONT = 'DejaVu Sans';

    /**
     * @return string the binary PDF
     *
     * @throws RuntimeException when a PHP extension dompdf needs for the logo is missing
     */
    public function render(Offer $offer): string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Creating the offer PDF needs the PHP "gd" extension (dompdf embeds the logo with it). Enable extension=gd.');
        }

        $workDir = $this->workDir();

        $options = new Options;
        $options->set('defaultFont', self::FONT);
        $options->set('isRemoteEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', $workDir);
        $options->set('fontCache', $workDir);
        $options->set('tempDir', $workDir);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($offer), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /** The HTML the PDF is made from (also what the tests look at). */
    public function html(Offer $offer): string
    {
        $offer->loadMissing('items.options');

        return view('pdf.offer', [
            'offer' => OfferPresenter::detail($offer, false),
            'appName' => config('app.name'),
            'logo' => $this->logo(),
        ])->render();
    }

    /** "ponuda-001-2026.pdf": the slash of the number is not allowed in a file name. */
    public static function filename(Offer $offer): string
    {
        return 'ponuda-'.trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $offer->number), '-').'.pdf';
    }

    private function logo(): ?string
    {
        $path = public_path('images/logo.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    private function workDir(): string
    {
        $dir = storage_path('app/pdf');
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
