import Checkbox from '@/Components/Checkbox';
import { groupBy, selectSingle, singleChoice, toggleOption } from '@/lib/configurator';
import { t, tOr } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { grossFromNet } from '@/lib/vat';

export const categoryLabel = (category) => tOr(`equipment.category.${category}`, category);

// "1.200,00 €" with the gross amount next to it (informative: the total is calculated once).
function Price({ cents, rateBp, surcharge = false }) {
    return (
        <span className="whitespace-nowrap text-sm text-gray-700">
            {surcharge ? '+ ' : ''}
            {formatMoney(cents)}
            <span className="ms-1 text-xs text-gray-500">({t('with VAT :amount', { amount: formatMoney(grossFromNet(cents, rateBp)) })})</span>
        </span>
    );
}

function Swatch({ hex }) {
    if (!hex) {
        return null;
    }

    return <span aria-hidden="true" className="inline-block h-4 w-4 shrink-0 rounded-full border border-gray-300" style={{ backgroundColor: hex }} />;
}

const rowClass = 'flex cursor-pointer items-center justify-between gap-3 rounded-md border border-gray-200 px-3 py-2 hover:bg-surface has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50';

// One-of-several group: the default (in the price) or exactly one surcharge.
function SingleGroup({ group, chosen, onChange, rateBp }) {
    const current = singleChoice(chosen, group);
    const name = `group-${group.id}`;

    return (
        <fieldset className="space-y-2">
            <legend className="text-sm font-semibold text-ink">
                {group.name} <span className="font-normal text-gray-500">· {categoryLabel(group.category)}</span>
            </legend>

            {group.default && (
                <label className={rowClass}>
                    <span className="flex items-center gap-2 text-sm">
                        <input type="radio" name={name} className="text-brand-600 focus:ring-brand-500" checked={current === null} onChange={() => onChange(selectSingle(chosen, group, null))} />
                        <Swatch hex={group.default.swatch_hex} />
                        {t('Default: :name', { name: group.default.name })}
                    </span>
                    <span className="text-sm text-gray-500">{t('included in the price')}</span>
                </label>
            )}

            {group.options.map((option) => (
                <label key={option.id} className={rowClass}>
                    <span className="flex items-center gap-2 text-sm">
                        <input type="radio" name={name} className="text-brand-600 focus:ring-brand-500" checked={current === option.id} onChange={() => onChange(selectSingle(chosen, group, option.id))} />
                        <Swatch hex={option.swatch_hex} />
                        {option.name}
                    </span>
                    <Price cents={option.price_cents} rateBp={rateBp} surcharge />
                </label>
            ))}
        </fieldset>
    );
}

function CheckRow({ option, checked, onToggle, rateBp }) {
    return (
        <label className={rowClass}>
            <span className="flex items-center gap-2 text-sm">
                <Checkbox checked={checked} onChange={onToggle} />
                <Swatch hex={option.swatch_hex} />
                {option.name}
            </span>
            <Price cents={option.price_cents} rateBp={rateBp} />
        </label>
    );
}

/**
 * Standard equipment (shown, no price) and the extras of one version: groups of "one of several"
 * as radio buttons, everything else as checkboxes with the net price.
 */
export default function EquipmentChoices({ detail, chosen, onChange, rateBp }) {
    const standardByCategory = groupBy(detail.standard, (item) => item.category);
    const multipleGroups = detail.groups.filter((group) => group.selection === 'multiple');
    const singleGroups = detail.groups.filter((group) => group.selection === 'single');
    const extrasByCategory = groupBy(detail.extras, (item) => item.category);

    return (
        <div className="space-y-6">
            <details className="rounded-md border border-gray-200 p-3" open={false}>
                <summary className="cursor-pointer text-sm font-semibold text-ink">
                    {t('Standard equipment (:count)', { count: detail.standard.length })}
                </summary>
                {detail.standard.length === 0 ? (
                    <p className="mt-2 text-sm text-gray-500">{t('No standard equipment is listed for this version.')}</p>
                ) : (
                    <div className="mt-3 grid gap-4 sm:grid-cols-2">
                        {standardByCategory.map(([category, items]) => (
                            <div key={category}>
                                <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">{categoryLabel(category)}</h4>
                                <ul className="mt-1 list-inside list-disc text-sm text-gray-700">
                                    {items.map((item) => <li key={item.id}>{item.name}</li>)}
                                </ul>
                            </div>
                        ))}
                    </div>
                )}
            </details>

            {singleGroups.map((group) => (
                <SingleGroup key={group.id} group={group} chosen={chosen} onChange={onChange} rateBp={rateBp} />
            ))}

            {multipleGroups.map((group) => (
                <fieldset key={group.id} className="space-y-2">
                    <legend className="text-sm font-semibold text-ink">
                        {group.name} <span className="font-normal text-gray-500">· {categoryLabel(group.category)}</span>
                    </legend>
                    {group.options.map((option) => (
                        <CheckRow key={option.id} option={option} checked={chosen.includes(option.id)} onToggle={() => onChange(toggleOption(chosen, option.id))} rateBp={rateBp} />
                    ))}
                </fieldset>
            ))}

            {extrasByCategory.map(([category, items]) => (
                <fieldset key={category} className="space-y-2">
                    <legend className="text-sm font-semibold text-ink">{t('Extras')} <span className="font-normal text-gray-500">· {categoryLabel(category)}</span></legend>
                    {items.map((option) => (
                        <CheckRow key={option.id} option={option} checked={chosen.includes(option.id)} onToggle={() => onChange(toggleOption(chosen, option.id))} rateBp={rateBp} />
                    ))}
                </fieldset>
            ))}

            {detail.groups.length === 0 && detail.extras.length === 0 && (
                <p className="text-sm text-gray-500">{t('This version has no extra equipment.')}</p>
            )}
        </div>
    );
}
