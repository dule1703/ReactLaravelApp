import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';

function Row({ label, seen, fresh }) {
    return (
        <tr className="border-t border-gray-200">
            <th scope="row" className="py-1 pe-3 text-left font-medium text-gray-700">{label}</th>
            <td className="py-1 pe-3 text-right text-gray-600 line-through">{formatMoney(seen)}</td>
            <td className="py-1 text-right font-semibold text-ink">{formatMoney(fresh)}</td>
        </tr>
    );
}

/**
 * The server calculated other totals than the ones the client saw (a price, the VAT rate or the
 * availability changed). Nothing is saved until the user confirms the new amounts explicitly.
 */
export default function ConflictPanel({ conflict, processing, onConfirm, onCancel }) {
    const { seen, fresh, message, rateChanged } = conflict;

    return (
        <div role="alertdialog" aria-labelledby="conflict-title" className="rounded-lg border border-danger bg-surface p-4">
            <h3 id="conflict-title" className="font-semibold text-ink">{t('The amounts have changed')}</h3>
            <p className="mt-1 text-sm text-gray-700">{message}</p>
            {rateChanged && <p className="mt-1 text-sm text-gray-700">{t('The VAT rate has also changed.')}</p>}

            <table className="mt-3 w-full text-sm">
                <thead>
                    <tr className="text-xs uppercase text-gray-500">
                        <th />
                        <th scope="col" className="pe-3 text-right font-medium">{t('You saw')}</th>
                        <th scope="col" className="text-right font-medium">{t('Now')}</th>
                    </tr>
                </thead>
                <tbody>
                    <Row label={t('Total without VAT')} seen={seen.total_net_cents} fresh={fresh.total_net_cents} />
                    <Row label={t('VAT')} seen={seen.vat_cents} fresh={fresh.vat_cents} />
                    <Row label={t('TOTAL OFFER')} seen={seen.total_gross_cents} fresh={fresh.total_gross_cents} />
                </tbody>
            </table>

            <div className="mt-4 flex flex-wrap gap-2">
                <PrimaryButton type="button" disabled={processing} onClick={onConfirm}>{t('Confirm new amounts and save')}</PrimaryButton>
                <SecondaryButton disabled={processing} onClick={onCancel}>{t('Cancel')}</SecondaryButton>
            </div>
        </div>
    );
}
