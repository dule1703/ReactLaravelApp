import { fieldA11y } from '@/lib/a11y';
import CatalogCrud from '@/Components/CatalogCrud';
import FormField from '@/Components/FormField';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import CatalogLayout from '@/Layouts/CatalogLayout';
import { t, tOr } from '@/lib/i18n';
import { formatMoney, parseEuros } from '@/lib/money';
import { grossFromNet, netFromGross } from '@/lib/vat';
import { Link } from '@inertiajs/react';

const MAX_PRICE_CENTS = 1_000_000_000;
const PARENT_LABELS = {
    car_model: 'Car model',
    trim: 'Trim',
    engine: 'Engine',
    transmission: 'Transmission',
};

const columns = [
    { header: t('Car model'), cell: (row) => <span className="font-medium text-ink">{row.model_name}</span> },
    { header: t('Trim'), cell: (row) => row.trim_name },
    {
        header: t('Engine'),
        cell: (row) => `${row.engine} (${row.power_kw} kW, ${tOr(`fuel.${row.fuel}`, row.fuel)})`,
    },
    { header: t('Transmission'), cell: (row) => `${row.transmission} · ${tOr(`drive.${row.drive}`, row.drive)}` },
    { header: t('Net'), cell: (row) => <span className="whitespace-nowrap font-mono">{formatMoney(row.net)}</span> },
    { header: t('Gross'), cell: (row) => <span className="whitespace-nowrap font-mono">{formatMoney(row.gross)}</span> },
];

function optionLabel(label, isActive) {
    return isActive ? label : `${label} (${t('inactive')})`;
}

function Fields({ data, setData, errors, context }) {
    const { trims, engines, transmissions, vat } = context;
    const models = [...new Set(trims.map((trim) => trim.model_name))];

    // Live preview with the same arithmetic as the server (lib/vat.js); the server decides.
    const typed = data.amount.trim() === '' ? null : parseEuros(data.amount);
    let preview = null;
    if (typed !== null && typed <= MAX_PRICE_CENTS) {
        preview =
            data.mode === 'net'
                ? t('Gross: :amount', { amount: formatMoney(grossFromNet(typed, vat.rate_bp)) })
                : t('Net: :amount', { amount: formatMoney(netFromGross(typed, vat.rate_bp)) });
    }

    return (
        <>
            <FormField id="trim_id" label={t('Trim')} error={errors.trim_id}>
                <SelectInput
                    id="trim_id" {...fieldA11y('trim_id', errors.trim_id)}
                    className="mt-1 block w-full"
                    value={data.trim_id}
                    required
                    onChange={(e) => setData('trim_id', e.target.value)}
                >
                    <option value="">{t('Choose')}</option>
                    {models.map((model) => (
                        <optgroup key={model} label={model}>
                            {trims
                                .filter((trim) => trim.model_name === model)
                                .map((trim) => (
                                    <option key={trim.id} value={trim.id}>
                                        {optionLabel(trim.name, trim.is_active)}
                                    </option>
                                ))}
                        </optgroup>
                    ))}
                </SelectInput>
            </FormField>

            <FormField id="engine_id" label={t('Engine')} error={errors.engine_id}>
                <SelectInput
                    id="engine_id" {...fieldA11y('engine_id', errors.engine_id)}
                    className="mt-1 block w-full"
                    value={data.engine_id}
                    required
                    onChange={(e) => setData('engine_id', e.target.value)}
                >
                    <option value="">{t('Choose')}</option>
                    {engines.map((engine) => (
                        <option key={engine.id} value={engine.id}>
                            {optionLabel(`${engine.name} (${engine.power_kw} kW, ${tOr(`fuel.${engine.fuel}`, engine.fuel)})`, engine.is_active)}
                        </option>
                    ))}
                </SelectInput>
            </FormField>

            <FormField id="transmission_id" label={t('Transmission')} error={errors.transmission_id}>
                <SelectInput
                    id="transmission_id" {...fieldA11y('transmission_id', errors.transmission_id)}
                    className="mt-1 block w-full"
                    value={data.transmission_id}
                    required
                    onChange={(e) => setData('transmission_id', e.target.value)}
                >
                    <option value="">{t('Choose')}</option>
                    {transmissions.map((transmission) => (
                        <option key={transmission.id} value={transmission.id}>
                            {optionLabel(`${transmission.name} · ${tOr(`drive.${transmission.drive}`, transmission.drive)}`, transmission.is_active)}
                        </option>
                    ))}
                </SelectInput>
            </FormField>

            <div className="flex flex-wrap items-start gap-3">
                <div>
                    <FormField id="amount" label={t('Price')} error={errors.amount} hint={preview}>
                        <TextInput
                            id="amount" {...fieldA11y('amount', errors.amount)}
                            className="mt-1 block w-40"
                            inputMode="decimal"
                            value={data.amount}
                            required
                            onChange={(e) => setData('amount', e.target.value)}
                        />
                    </FormField>
                </div>
                <FormField id="mode" label={t('Entered as')} error={errors.mode}>
                    <SelectInput
                        id="mode" {...fieldA11y('mode', errors.mode)}
                        className="mt-1 block"
                        value={data.mode}
                        onChange={(e) => setData('mode', e.target.value)}
                    >
                        <option value="net">{t('Net')}</option>
                        <option value="gross">{t('Gross')}</option>
                    </SelectInput>
                </FormField>
            </div>
            <p className="text-sm text-gray-600">
                {t('The combination cannot be changed later; the price is edited on the Prices screen.')}
            </p>
        </>
    );
}

export default function Versions({ items, filters, vat, models, trims, engines, transmissions }) {
    return (
        <CatalogLayout active="versions">
            <CatalogCrud
                resource="catalog.versions"
                entity={t('Version')}
                items={items}
                filters={filters}
                columns={columns}
                canEdit={false}
                Form={Fields}
                formContext={{ trims, engines, transmissions, vat }}
                initialData={() => ({ trim_id: '', engine_id: '', transmission_id: '', mode: 'net', amount: '' })}
                deleteNote={t('Note: the seeder may bring a deleted row back (firstOrCreate); deactivation is recommended.')}
                filterFields={[
                    {
                        name: 'model',
                        label: t('Car model'),
                        options: models.map((model) => ({ value: String(model.id), label: optionLabel(model.name, model.is_active) })),
                    },
                    {
                        name: 'trim',
                        label: t('Trim'),
                        options: trims.map((trim) => ({ value: String(trim.id), label: `${trim.model_name} / ${optionLabel(trim.name, trim.is_active)}` })),
                    },
                    {
                        name: 'status',
                        label: t('Status'),
                        options: [
                            { value: 'active', label: t('Active') },
                            { value: 'inactive', label: t('Inactive') },
                            { value: 'unavailable', label: t('Active, but not offered') },
                        ],
                    },
                ]}
                rowNote={(row) =>
                    row.inactive_parents.length > 0 ? (
                        <p className="mt-1 text-xs italic text-yellow-800">
                            {t('Not offered: inactive :parents.', {
                                parents: row.inactive_parents.map((parent) => t(PARENT_LABELS[parent]).toLowerCase()).join(', '),
                            })}
                        </p>
                    ) : null
                }
                rowExtra={(row) => (
                    <Link href={row.price_url} className="me-3 font-medium text-brand-700 hover:text-brand-800">
                        {t('Price')}
                    </Link>
                )}
            />
        </CatalogLayout>
    );
}
