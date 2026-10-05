import SecondaryButton from '@/Components/SecondaryButton';
import { unitNetCents } from '@/lib/configurator';
import { t, tOr } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { grossFromNet } from '@/lib/vat';

export const describeVersion = (version) => {
    const fuel = tOr(`fuel.${version.engine.fuel_type}`, version.engine.fuel_type);
    const gearbox = tOr(`transmission.type.${version.transmission.type}`, version.transmission.type);

    return `${version.engine.name} · ${version.engine.power_kw} kW · ${fuel} · ${version.transmission.name} (${gearbox})`;
};

/**
 * The items of the offer so far. The line amounts are what the page calculated; the gross value
 * of a line is informative (the offer total is calculated once, so it can differ by a cent from
 * the sum of the lines).
 */
export default function ItemList({ items, lines, rateBp, editingUid, onEdit, onRemove, disabled }) {
    if (items.length === 0) {
        return <p className="text-sm text-gray-500">{t('No models added yet. Choose a model, a version and the equipment, then press "Save model".')}</p>;
    }

    return (
        <ol className="space-y-3">
            {items.map((item, index) => {
                const net = lines?.[index]?.line_net_cents ?? unitNetCents(item) * item.quantity;

                return (
                    <li key={item.uid} className={`rounded-lg border p-3 ${editingUid === item.uid ? 'border-brand-500 bg-brand-50' : 'border-gray-200 bg-white'}`}>
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="font-semibold text-ink">
                                    {item.quantity} × {item.version.model} {item.version.trim}
                                </p>
                                <p className="text-xs text-gray-600">{describeVersion(item.version)}</p>
                            </div>
                            <div className="text-right">
                                <p className="font-semibold text-ink">{formatMoney(net)}</p>
                                <p className="text-xs text-gray-500">{t('with VAT :amount', { amount: formatMoney(grossFromNet(net, rateBp)) })}</p>
                            </div>
                        </div>

                        {item.options.length > 0 && (
                            <ul className="mt-2 list-inside list-disc text-xs text-gray-700">
                                {item.options.map((option) => (
                                    <li key={option.id}>
                                        {option.name}
                                        {option.is_surcharge && <span className="text-gray-500"> ({t('surcharge')})</span>}
                                        <span className="text-gray-500"> · {formatMoney(option.price_cents)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <div className="mt-3 flex gap-2">
                            <SecondaryButton disabled={disabled} onClick={() => onEdit(item)}>{t('Edit')}</SecondaryButton>
                            <SecondaryButton disabled={disabled} onClick={() => onRemove(item)}>{t('Remove')}</SecondaryButton>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
