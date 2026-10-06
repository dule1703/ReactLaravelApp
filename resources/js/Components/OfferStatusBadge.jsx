import { t } from '@/lib/i18n';

/** "Withdrawn" mark of an offer (4.6b); nothing for an offer that is not withdrawn. */
export default function OfferStatusBadge({ offer }) {
    if (!offer.withdrawn_at) {
        return null;
    }

    return (
        <span className="rounded bg-red-50 px-1.5 py-0.5 text-xs font-semibold text-red-700" title={t('Withdrawn on :date', { date: offer.withdrawn_at })}>
            {t('Withdrawn')}
        </span>
    );
}
