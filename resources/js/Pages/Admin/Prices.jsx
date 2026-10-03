import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t, tOr } from '@/lib/i18n';
import { formatMoney, parseEuros } from '@/lib/money';
import { grossFromNet, netFromGross } from '@/lib/vat';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const MAX_PRICE_CENTS = 1_000_000_000;

function Card({ title, children }) {
    return (
        <section className="bg-white p-4 shadow sm:rounded-lg sm:p-6">
            {title && <h3 className="mb-4 text-lg font-medium text-ink">{title}</h3>}
            {children}
        </section>
    );
}

function VatForm({ vat }) {
    const { data, setData, patch, errors, processing } = useForm({ rate: vat.rate_percent });

    const submit = (e) => {
        e.preventDefault();
        patch(route('prices.vat.update'), { preserveScroll: true });
    };

    return (
        <Card title={t('VAT rate')}>
            <form onSubmit={submit} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div>
                    <label htmlFor="vat_rate" className="block text-sm font-medium text-gray-700">
                        {t('VAT rate (%)')}
                    </label>
                    <TextInput
                        id="vat_rate"
                        className="mt-1 block w-32"
                        inputMode="decimal"
                        value={data.rate}
                        onChange={(e) => setData('rate', e.target.value)}
                    />
                    <InputError className="mt-2" message={errors.rate} />
                </div>
                <PrimaryButton disabled={processing}>{t('Save')}</PrimaryButton>
            </form>
            <p className="mt-3 text-sm text-gray-600">
                {t('Prices are stored without VAT. Changing the rate does not change stored prices, only the gross prices shown.')}
            </p>
        </Card>
    );
}

// One editable price: type a net OR a gross amount; the server derives the net price itself.
// The preview below the field uses the same VAT arithmetic as the server (lib/vat.js).
function PriceEditor({ action, net, gross, rateBp }) {
    const { data, setData, patch, errors, processing, reset } = useForm({ mode: 'net', amount: '' });

    const typed = data.amount.trim() === '' ? null : parseEuros(data.amount);
    let preview = null;
    if (typed !== null && typed <= MAX_PRICE_CENTS) {
        preview =
            data.mode === 'net'
                ? t('Gross: :amount', { amount: formatMoney(grossFromNet(typed, rateBp)) })
                : t('Net: :amount', { amount: formatMoney(netFromGross(typed, rateBp)) });
    }

    const submit = (e) => {
        e.preventDefault();
        patch(action, { preserveScroll: true, onSuccess: () => reset('amount') });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
            <div>
                <TextInput
                    aria-label={t('New price')}
                    className="block w-32 text-sm"
                    inputMode="decimal"
                    placeholder={formatMoney(net).replace(/[^\d.,]/g, '')}
                    value={data.amount}
                    onChange={(e) => setData('amount', e.target.value)}
                />
                {preview && <p className="mt-1 text-xs text-gray-500">{preview}</p>}
                <InputError className="mt-1" message={errors.amount} />
            </div>
            <SelectInput
                aria-label={t('Entered as')}
                className="block text-sm"
                value={data.mode}
                onChange={(e) => setData('mode', e.target.value)}
            >
                <option value="net">{t('Net')}</option>
                <option value="gross">{t('Gross')}</option>
            </SelectInput>
            <PrimaryButton disabled={processing || data.amount.trim() === ''}>{t('Save')}</PrimaryButton>
        </form>
    );
}

function Money({ cents }) {
    return <span className="whitespace-nowrap font-mono">{formatMoney(cents)}</span>;
}

function VersionsTable({ trim, rateBp }) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                <thead className="bg-surface text-xs uppercase text-gray-500">
                    <tr>
                        <th className="px-3 py-2">{t('Engine')}</th>
                        <th className="px-3 py-2">{t('Transmission')}</th>
                        <th className="px-3 py-2">{t('Net')}</th>
                        <th className="px-3 py-2">{t('Gross')}</th>
                        <th className="px-3 py-2">{t('Change price')}</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {trim.versions.map((version) => (
                        <tr key={version.id} className={version.is_active ? '' : 'text-gray-400'}>
                            <td className="px-3 py-2 align-top">
                                {version.engine} ({version.power_kw} kW, {tOr(`fuel.${version.fuel}`, version.fuel)})
                                {!version.is_active && <span className="ms-2 text-xs italic">{t('inactive')}</span>}
                            </td>
                            <td className="px-3 py-2 align-top">
                                {version.transmission} · {tOr(`drive.${version.drive}`, version.drive)}
                            </td>
                            <td className="px-3 py-2 align-top"><Money cents={version.net} /></td>
                            <td className="px-3 py-2 align-top"><Money cents={version.gross} /></td>
                            <td className="px-3 py-2">
                                <PriceEditor
                                    action={route('prices.versions.update', version.id)}
                                    net={version.net}
                                    gross={version.gross}
                                    rateBp={rateBp}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function EquipmentTable({ trim, rateBp }) {
    if (trim.equipment.length === 0) {
        return <p className="text-sm text-gray-500">{t('No optional equipment with a price.')}</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                <thead className="bg-surface text-xs uppercase text-gray-500">
                    <tr>
                        <th className="px-3 py-2">{t('Equipment')}</th>
                        <th className="px-3 py-2">{t('Category')}</th>
                        <th className="px-3 py-2">{t('Net')}</th>
                        <th className="px-3 py-2">{t('Gross')}</th>
                        <th className="px-3 py-2">{t('Change price')}</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {trim.equipment.map((row) => (
                        <tr key={row.id}>
                            <td className="px-3 py-2 align-top">{row.name}</td>
                            <td className="px-3 py-2 align-top">
                                {tOr(`equipment.category.${row.category}`, row.category)}
                            </td>
                            <td className="px-3 py-2 align-top"><Money cents={row.net} /></td>
                            <td className="px-3 py-2 align-top"><Money cents={row.gross} /></td>
                            <td className="px-3 py-2">
                                <PriceEditor
                                    action={route('prices.equipment.update', row.id)}
                                    net={row.net}
                                    gross={row.gross}
                                    rateBp={rateBp}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function errorMessage(error) {
    const data = error.response?.data;

    if (data?.errors) {
        return Object.values(data.errors).flat().join(' ');
    }

    return data?.message ?? t('Something went wrong. Try again.');
}

// Bulk change: filter, change, REVIEW (old -> new, net and gross), then apply. The server
// recomputes everything on apply and refuses it if the prices changed since the preview.
function BulkPanel({ modelId, trims }) {
    const [form, setForm] = useState({
        trim_id: '',
        targets: ['versions', 'equipment'],
        change_type: 'percent',
        amount_mode: 'gross',
        value: '',
    });
    const [preview, setPreview] = useState(null);
    const [confirmLarge, setConfirmLarge] = useState(false);
    const [message, setMessage] = useState(null);
    const [busy, setBusy] = useState(false);

    const change = (patch) => {
        setForm((current) => ({ ...current, ...patch }));
        setPreview(null);
        setConfirmLarge(false);
        setMessage(null);
    };

    const toggleTarget = (target) =>
        change({
            targets: form.targets.includes(target)
                ? form.targets.filter((item) => item !== target)
                : [...form.targets, target],
        });

    const payload = () => ({
        car_model_id: modelId,
        trim_id: form.trim_id || null,
        targets: form.targets,
        change_type: form.change_type,
        amount_mode: form.change_type === 'amount' ? form.amount_mode : null,
        value: form.value,
    });

    const run = async (action) => {
        setBusy(true);
        setMessage(null);

        try {
            await action();
        } catch (error) {
            setMessage({ type: 'error', text: errorMessage(error) });
        } finally {
            setBusy(false);
        }
    };

    const showPreview = () =>
        run(async () => {
            const response = await window.axios.post(route('prices.bulk.preview'), payload());
            setPreview(response.data);
            setConfirmLarge(false);
        });

    const apply = () =>
        run(async () => {
            const response = await window.axios.post(route('prices.bulk.apply'), {
                ...payload(),
                token: preview.token,
                confirm_large: confirmLarge,
            });
            setPreview(null);
            setForm((current) => ({ ...current, value: '' }));
            setMessage({ type: 'success', text: response.data.message });
            router.reload({ preserveScroll: true });
        });

    return (
        <Card title={t('Bulk price change')}>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label htmlFor="bulk_trim" className="block text-sm font-medium text-gray-700">
                        {t('Trim')}
                    </label>
                    <SelectInput
                        id="bulk_trim"
                        className="mt-1 block w-full"
                        value={form.trim_id}
                        onChange={(e) => change({ trim_id: e.target.value })}
                    >
                        <option value="">{t('All trims of the model')}</option>
                        {trims.map((trim) => (
                            <option key={trim.id} value={trim.id}>
                                {trim.name}
                            </option>
                        ))}
                    </SelectInput>
                </div>

                <fieldset>
                    <legend className="block text-sm font-medium text-gray-700">{t('Applies to')}</legend>
                    <label className="mt-2 flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.targets.includes('versions')}
                            onChange={() => toggleTarget('versions')}
                        />
                        {t('Base prices of versions')}
                    </label>
                    <label className="mt-1 flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.targets.includes('equipment')}
                            onChange={() => toggleTarget('equipment')}
                        />
                        {t('Optional equipment')}
                    </label>
                </fieldset>

                <div>
                    <label htmlFor="bulk_type" className="block text-sm font-medium text-gray-700">
                        {t('Change')}
                    </label>
                    <SelectInput
                        id="bulk_type"
                        className="mt-1 block w-full"
                        value={form.change_type}
                        onChange={(e) => change({ change_type: e.target.value })}
                    >
                        <option value="percent">{t('Percentage (%)')}</option>
                        <option value="amount">{t('Fixed amount (€)')}</option>
                    </SelectInput>
                    {form.change_type === 'amount' && (
                        <SelectInput
                            aria-label={t('Entered as')}
                            className="mt-2 block w-full"
                            value={form.amount_mode}
                            onChange={(e) => change({ amount_mode: e.target.value })}
                        >
                            <option value="gross">{t('Added to the gross price')}</option>
                            <option value="net">{t('Added to the net price')}</option>
                        </SelectInput>
                    )}
                </div>

                <div>
                    <label htmlFor="bulk_value" className="block text-sm font-medium text-gray-700">
                        {form.change_type === 'percent' ? t('Percentage (e.g. 3,5 or -2)') : t('Amount (e.g. 250 or -100)')}
                    </label>
                    <TextInput
                        id="bulk_value"
                        className="mt-1 block w-full"
                        inputMode="decimal"
                        value={form.value}
                        onChange={(e) => change({ value: e.target.value })}
                    />
                </div>
            </div>

            <p className="mt-3 text-sm text-gray-600">
                {t('The new gross price is rounded to a whole euro and the net price is derived from it.')}
            </p>

            <div className="mt-4 flex gap-2">
                <PrimaryButton type="button" onClick={showPreview} disabled={busy || form.value.trim() === '' || form.targets.length === 0}>
                    {t('Review')}
                </PrimaryButton>
                {preview && (
                    <SecondaryButton onClick={() => setPreview(null)}>{t('Cancel')}</SecondaryButton>
                )}
            </div>

            {message && (
                <p
                    role={message.type === 'error' ? 'alert' : 'status'}
                    className={`mt-3 text-sm ${message.type === 'error' ? 'text-danger' : 'text-brand-700'}`}
                >
                    {message.text}
                </p>
            )}

            {preview && (
                <div className="mt-6">
                    <h4 className="text-sm font-semibold text-ink">
                        {t(':count prices will change', { count: preview.items.length })}
                    </h4>
                    <div className="mt-2 max-h-96 overflow-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                            <thead className="sticky top-0 bg-surface text-xs uppercase text-gray-500">
                                <tr>
                                    <th className="px-3 py-2">{t('Item')}</th>
                                    <th className="px-3 py-2">{t('Net: old → new')}</th>
                                    <th className="px-3 py-2">{t('Gross: old → new')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {preview.items.map((item) => (
                                    <tr key={`${item.kind}-${item.id}`}>
                                        <td className="px-3 py-2">{item.label}</td>
                                        <td className="px-3 py-2">
                                            <Money cents={item.old_net} /> → <Money cents={item.new_net} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <Money cents={item.old_gross} /> → <Money cents={item.new_gross} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {preview.requires_confirmation && (
                        <label className="mt-4 flex items-start gap-2 text-sm text-danger">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={confirmLarge}
                                onChange={(e) => setConfirmLarge(e.target.checked)}
                            />
                            {t('Some prices change by more than 10%. I confirm this change.')}
                        </label>
                    )}

                    <div className="mt-4">
                        <PrimaryButton
                            type="button"
                            onClick={apply}
                            disabled={busy || (preview.requires_confirmation && !confirmLarge)}
                        >
                            {t('Apply')}
                        </PrimaryButton>
                    </div>
                </div>
            )}
        </Card>
    );
}

export default function Prices({ vat, models, selectedModelId, trims }) {
    const selectModel = (id) => router.get(route('prices.index'), { model: id });

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-ink">{t('Prices')}</h2>}
        >
            <Head title={t('Prices')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <VatForm vat={vat} />

                    {models.length === 0 ? (
                        <Card>
                            <p className="text-sm text-gray-600">{t('The catalog is empty.')}</p>
                        </Card>
                    ) : (
                        <>
                            <Card>
                                <label htmlFor="model" className="block text-sm font-medium text-gray-700">
                                    {t('Car model')}
                                </label>
                                <SelectInput
                                    id="model"
                                    className="mt-1 block w-full sm:w-72"
                                    value={selectedModelId ?? ''}
                                    onChange={(e) => selectModel(e.target.value)}
                                >
                                    {models.map((model) => (
                                        <option key={model.id} value={model.id}>
                                            {model.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </Card>

                            <BulkPanel key={selectedModelId} modelId={selectedModelId} trims={trims} />

                            {trims.map((trim) => (
                                <Card key={trim.id} title={`${t('Trim')}: ${trim.name}`}>
                                    <VersionsTable trim={trim} rateBp={vat.rate_bp} />

                                    <h4 className="mb-2 mt-6 text-sm font-semibold text-ink">
                                        {t('Optional equipment')}
                                    </h4>
                                    <EquipmentTable trim={trim} rateBp={vat.rate_bp} />
                                </Card>
                            ))}
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
