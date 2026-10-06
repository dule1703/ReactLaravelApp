import { t } from '@/lib/i18n';
import { formatMoney, formatRateBp } from '@/lib/money';

/** Net, VAT and gross of the whole offer; the rate is the one the server applies when saving. */
export default function Totals({ totals, rateBp, error }) {
    if (error) {
        return <p role="alert" className="text-sm text-danger">{t('The offer exceeds the allowed limits. Remove an item or some equipment.')}</p>;
    }


    return (
        <dl className="space-y-1 text-sm">
            <div className="flex justify-between">
                <dt className="text-gray-600">{t('Total without VAT')}</dt>
                <dd className="font-medium text-ink">{formatMoney(totals.total_net_cents)}</dd>
            </div>
            <div className="flex justify-between">
                <dt className="text-gray-600">{t('VAT :rate%', { rate: formatRateBp(rateBp) })}</dt>
                <dd className="font-medium text-ink">{formatMoney(totals.vat_cents)}</dd>
            </div>
            <div className="flex justify-between border-t border-gray-200 pt-2 text-base">
                <dt className="font-semibold text-ink">{t('TOTAL OFFER')}</dt>
                <dd className="font-bold text-brand-700" aria-live="polite">{formatMoney(totals.total_gross_cents)}</dd>
            </div>
        </dl>
    );
}
