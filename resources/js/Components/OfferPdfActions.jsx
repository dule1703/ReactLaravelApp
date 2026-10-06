import { t } from '@/lib/i18n';

// Plain links, not Inertia <Link>: the answer is a PDF, not an Inertia page. "Print" opens the same
// PDF inline in a new tab (printing is then the browser's own action; the server only sees the
// opening); "PDF" asks for an attachment (?download=1).
const base = 'inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white font-medium text-ink shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2';

function PrinterIcon() {
    return (
        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M5 2a2 2 0 00-2 2v2H2.5A1.5 1.5 0 001 7.5v5A1.5 1.5 0 002.5 14H3v2a2 2 0 002 2h10a2 2 0 002-2v-2h.5a1.5 1.5 0 001.5-1.5v-5A1.5 1.5 0 0017.5 6H17V4a2 2 0 00-2-2H5zm0 2h10v2H5V4zm0 8h10v4H5v-4z" />
        </svg>
    );
}

function DownloadIcon() {
    return (
        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M10 2a1 1 0 011 1v8.6l2.3-2.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L9 11.6V3a1 1 0 011-1zM4 15a1 1 0 011 1v1h10v-1a1 1 0 112 0v1a2 2 0 01-2 2H5a2 2 0 01-2-2v-1a1 1 0 011-1z" />
        </svg>
    );
}

/**
 * The "Print" and "PDF" buttons of an offer. `compact` shows icons only (the list); each link has
 * an aria-label and a title that name the offer.
 */
export default function OfferPdfActions({ offer, compact = false }) {
    const size = compact ? 'p-1.5' : 'px-3 py-1.5 text-sm';
    const printLabel = t('Open offer :number as PDF for printing (new tab)', { number: offer.number });
    const downloadLabel = t('Download offer :number as PDF', { number: offer.number });

    return (
        <span className="inline-flex items-center gap-2">
            <a
                href={route('offers.pdf', offer.id)}
                target="_blank"
                rel="noopener"
                aria-label={printLabel}
                title={printLabel}
                className={`${base} ${size}`}
            >
                <PrinterIcon />
                {!compact && t('Print')}
            </a>
            <a
                href={route('offers.pdf', { offer: offer.id, download: 1 })}
                aria-label={downloadLabel}
                title={downloadLabel}
                className={`${base} ${size}`}
            >
                <DownloadIcon />
                {!compact && t('PDF')}
            </a>
        </span>
    );
}
