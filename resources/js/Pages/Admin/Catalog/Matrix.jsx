import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import CatalogLayout from '@/Layouts/CatalogLayout';
import { t, tOr } from '@/lib/i18n';
import { cellKey, centsToInput, expectedOf, groupEntriesOnTrim, indexEntries, otherStandard, stateOf } from '@/lib/matrix';
import { formatMoney, parseEuros } from '@/lib/money';
import { grossFromNet, netFromGross } from '@/lib/vat';
import { errorId } from '@/lib/a11y';
import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const MAX_PRICE_CENTS = 1_000_000_000;
const categoryLabel = (category) => tOr(`equipment.category.${category}`, category);
const STATE_LABEL = { none: 'Unavailable', standard: 'Standard', optional: 'Extra' };

// First message of a failed request: a 422 carries per-field messages, a 409 a single message.
function errorMessages(error) {
    const data = error.response?.data;

    if (error.response?.status === 422 && data?.errors) {
        return Object.values(data.errors).flat();
    }

    return [data?.message ?? t('Saving failed. Try again.')];
}

// A price typed net OR gross with a live preview (same VAT arithmetic as the server).
function PriceField({ id, label, mode, amount, onMode, onAmount, rateBp, error, hint }) {
    const typed = amount.trim() === '' ? null : parseEuros(amount);
    let preview = null;

    if (typed !== null && typed <= MAX_PRICE_CENTS) {
        preview =
            mode === 'net'
                ? t('Gross: :amount', { amount: formatMoney(grossFromNet(typed, rateBp)) })
                : t('Net: :amount', { amount: formatMoney(netFromGross(typed, rateBp)) });
    }

    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium text-gray-700">{label}</label>
            <div className="mt-1 flex items-start gap-2">
                <div>
                    <TextInput
                        id={id}
                        className="block w-32 text-sm"
                        inputMode="decimal"
                        value={amount}
                        onChange={(e) => onAmount(e.target.value)}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={error ? `${id}-hint ${errorId(id)}` : `${id}-hint`}
                    />
                    <p id={`${id}-hint`} className="mt-1 text-xs text-gray-500">{preview ?? hint}</p>
                </div>
                <SelectInput aria-label={t('Entered as')} className="block text-sm" value={mode} onChange={(e) => onMode(e.target.value)}>
                    <option value="net">{t('Net')}</option>
                    <option value="gross">{t('Gross')}</option>
                </SelectInput>
            </div>
            <InputError id={errorId(id)} className="mt-1" message={error} />
        </div>
    );
}

// The dialog of one cell: the three states, the price, the replacement of the standard item of a
// single-choice group and the explicit removal of the whole group. Saved per cell.
function CellDialog({ ctx, modelId, rateBp, onClose, onSaved }) {
    const { trim, item, entry, entries } = ctx;
    const groupEntries = groupEntriesOnTrim(entries, trim.id, item.group_id);
    const current = stateOf(entry);
    const single = item.selection === 'single';

    const [target, setTarget] = useState(current);
    const [mode, setMode] = useState('net');
    const [amount, setAmount] = useState(entry?.availability === 'optional' ? centsToInput(entry.price_cents) : '');
    const [previous, setPrevious] = useState('none');
    const [prevMode, setPrevMode] = useState('net');
    const [prevAmount, setPrevAmount] = useState('');
    const [errors, setErrors] = useState([]);
    const [conflict, setConflict] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [confirmGroup, setConfirmGroup] = useState(false);

    // Items that are locked (inactive item or group) can only be removed.
    const canAssign = item.locked === null;
    const replaced = target === 'standard' ? otherStandard(item, groupEntries) : null;
    const changed = target !== current || (target === 'optional' && amount !== centsToInput(entry?.price_cents ?? -1));

    const run = async (request) => {
        setProcessing(true);
        setErrors([]);

        try {
            const response = await request();
            onSaved(response.data.message);
        } catch (error) {
            setConflict(error.response?.status === 409);
            setErrors(errorMessages(error));
        } finally {
            setProcessing(false);
        }
    };

    const save = (e) => {
        e.preventDefault();

        const payload = {
            car_model_id: modelId,
            trim_id: trim.id,
            equipment_item_id: item.id,
            availability: target,
            expected: expectedOf(entry),
        };

        if (target === 'optional') {
            payload.mode = mode;
            payload.amount = amount;
        }

        if (single && target === 'standard') {
            payload.expected_standard_item_id = replaced?.item_id ?? null;
        }

        if (replaced) {
            payload.previous = previous === 'optional'
                ? { availability: 'optional', mode: prevMode, amount: prevAmount }
                : { availability: 'none' };
        }

        run(() => window.axios.put(route('catalog.matrix.cell'), payload));
    };

    const removeGroup = () => run(() => window.axios.delete(route('catalog.matrix.group'), {
        data: {
            car_model_id: modelId,
            trim_id: trim.id,
            option_group_id: item.group_id,
            confirm: true,
            expected: groupEntries.map((row) => ({
                equipment_item_id: row.item_id,
                availability: row.availability,
                price_cents: row.availability === 'optional' ? row.price_cents : null,
            })),
        },
    }));

    const radio = (value) => (
        <label key={value} className={`flex items-center gap-2 text-sm ${value !== 'none' && !canAssign ? 'text-gray-400' : ''}`}>
            <input
                type="radio"
                name="state"
                className="text-brand-600 focus:ring-brand-500"
                checked={target === value}
                disabled={value !== 'none' && !canAssign}
                onChange={() => setTarget(value)}
            />
            {t(STATE_LABEL[value])}
        </label>
    );

    return (
        <Modal show onClose={onClose} maxWidth="md">
            <div className="p-6">
                <h3 className="text-lg font-semibold text-ink">{item.name}</h3>
                <p className="text-sm text-gray-600">
                    {trim.name}
                    {item.group_name ? ` · ${item.group_name}` : ''}
                </p>

                {item.locked && (
                    <p className="mt-2 text-xs italic text-yellow-800">
                        {item.locked === 'item' ? t('Not offered: the item is inactive.') : t('Not offered: the option group is inactive.')}
                    </p>
                )}

                {confirmGroup ? (
                    <div className="mt-4 space-y-3" role="alertdialog" aria-label={t('Remove the whole group from the line')}>
                        <p className="text-sm text-ink">
                            {t('Remove the group :group from the line :trim? All its entries on this line are deleted.', { group: item.group_name, trim: trim.name })}
                        </p>
                        <ul className="list-disc ps-5 text-sm text-gray-700">
                            {groupEntries.map((row) => <li key={row.item_id}>{row.item_name}</li>)}
                        </ul>
                        <div className="flex flex-wrap gap-2">
                            <PrimaryButton type="button" disabled={processing} onClick={removeGroup}>{t('Remove')}</PrimaryButton>
                            <SecondaryButton onClick={() => setConfirmGroup(false)}>{t('Cancel')}</SecondaryButton>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={save} className="mt-4 space-y-4">
                        <fieldset>
                            <legend className="text-sm font-medium text-gray-700">{t('Cell')}</legend>
                            <div className="mt-2 flex flex-wrap gap-4">{['none', 'standard', 'optional'].map(radio)}</div>
                        </fieldset>

                        {target === 'optional' && (
                            <div className="space-y-1">
                                <PriceField
                                    id="matrix-price"
                                    label={t('Price (net or gross)')}
                                    mode={mode}
                                    amount={amount}
                                    onMode={setMode}
                                    onAmount={setAmount}
                                    rateBp={rateBp}
                                    hint={single ? t('Surcharge over the standard item of the group, not the total price.') : null}
                                />
                            </div>
                        )}

                        {replaced && (
                            <fieldset className="space-y-2 rounded-md border border-gray-200 p-3">
                                <legend className="px-1 text-sm font-medium text-gray-700">{t('Replace the standard item')}</legend>
                                <p className="text-sm text-gray-600">{t('The previous standard item is :item. What should it become?', { item: replaced.item_name })}</p>
                                <div className="flex gap-4">
                                    {['none', 'optional'].map((value) => (
                                        <label key={value} className="flex items-center gap-2 text-sm">
                                            <input type="radio" name="previous" className="text-brand-600 focus:ring-brand-500" checked={previous === value} onChange={() => setPrevious(value)} />
                                            {t(value === 'none' ? 'Unavailable' : 'Extra')}
                                        </label>
                                    ))}
                                </div>
                                {previous === 'optional' && (
                                    <PriceField
                                        id="matrix-previous-price"
                                        label={t('Price (net or gross)')}
                                        mode={prevMode}
                                        amount={prevAmount}
                                        onMode={setPrevMode}
                                        onAmount={setPrevAmount}
                                        rateBp={rateBp}
                                        hint={t('Surcharge over the standard item of the group, not the total price.')}
                                    />
                                )}
                            </fieldset>
                        )}

                        <div role="alert" aria-live="polite">
                            {errors.map((message) => <InputError key={message} message={message} />)}
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <PrimaryButton disabled={processing || !changed}>{t('Save')}</PrimaryButton>
                            <SecondaryButton onClick={onClose}>{t('Cancel')}</SecondaryButton>
                            {conflict && <SecondaryButton onClick={() => onSaved(null)}>{t('Refresh')}</SecondaryButton>}
                            {groupEntries.length > 0 && (
                                <button type="button" className="ms-auto text-sm text-danger underline focus:outline-none focus:ring-2 focus:ring-brand-500" onClick={() => setConfirmGroup(true)}>
                                    {t('Remove the whole group from the line')}
                                </button>
                            )}
                        </div>
                    </form>
                )}
                {confirmGroup && <div role="alert" aria-live="polite" className="mt-2">{errors.map((message) => <InputError key={message} message={message} />)}</div>}
            </div>
        </Modal>
    );
}

function CellButton({ trim, item, entry, onOpen }) {
    const state = stateOf(entry);
    const disabled = item.locked !== null && state === 'none';
    const text = state === 'standard' ? 'S' : state === 'optional' ? `+${formatMoney(entry.price_cents)}` : '—';
    const label = `${trim.name} · ${item.name}: ${t(STATE_LABEL[state])}${state === 'optional' ? ` ${formatMoney(entry.price_cents)}` : ''}`;
    const style = {
        none: 'text-gray-400 hover:bg-gray-50',
        standard: 'bg-brand-50 font-semibold text-brand-700 hover:bg-brand-100',
        optional: 'bg-white text-ink hover:bg-gray-50',
    }[state];

    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={() => onOpen(trim, item, entry)}
            className={`block w-full min-w-24 px-3 py-2 text-center text-sm focus:outline-none focus:ring-2 focus:ring-inset focus:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-40 ${style}`}
        >
            {text}
        </button>
    );
}

export default function Matrix({ models, selectedModelId, filters, categories, groups, vatRateBp, trims, items, entries }) {
    const [dialog, setDialog] = useState(null);
    const [message, setMessage] = useState(null);
    const [q, setQ] = useState(filters.q ?? '');
    const index = useMemo(() => indexEntries(entries), [entries]);

    const visit = (changes) => router.get(
        route('catalog.matrix.index'),
        { model: selectedModelId, q, category: filters.category, group: filters.group, entered: filters.entered ? 1 : 0, ...changes },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    const closeAndReload = (savedMessage) => {
        setDialog(null);
        setMessage(savedMessage);
        router.reload({ preserveScroll: true, preserveState: true });
    };

    const byCategory = categories
        .map((category) => ({ category, rows: items.filter((item) => item.category === category) }))
        .filter((section) => section.rows.length > 0);
    const columns = trims.length + 1;

    return (
        <CatalogLayout active="matrix">
            <div className="space-y-4 px-4 sm:px-0">
                {models.length === 0 ? (
                    <p className="text-sm text-gray-600">
                        {t('No car models yet.')}{' '}
                        <Link href={route('catalog.models.index')} className="font-semibold text-brand-700 underline">{t('Add a car model in the catalog')}</Link>
                    </p>
                ) : (
                    <>
                        <form
                            className="flex flex-wrap items-end gap-3"
                            onSubmit={(e) => { e.preventDefault(); visit({ q }); }}
                        >
                            <div>
                                <label htmlFor="model" className="block text-sm font-medium text-gray-700">{t('Car model')}</label>
                                <SelectInput id="model" className="mt-1 block text-sm" value={selectedModelId ?? ''} onChange={(e) => visit({ model: e.target.value, group: undefined, category: undefined })}>
                                    {models.map((model) => <option key={model.id} value={model.id}>{model.name}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <label htmlFor="category" className="block text-sm font-medium text-gray-700">{t('Category')}</label>
                                <SelectInput id="category" className="mt-1 block text-sm" value={filters.category ?? ''} onChange={(e) => visit({ category: e.target.value || undefined })}>
                                    <option value="">{t('All categories')}</option>
                                    {categories.map((category) => <option key={category} value={category}>{categoryLabel(category)}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <label htmlFor="group" className="block text-sm font-medium text-gray-700">{t('Option group')}</label>
                                <SelectInput id="group" className="mt-1 block text-sm" value={filters.group ?? ''} onChange={(e) => visit({ group: e.target.value || undefined })}>
                                    <option value="">{t('All groups')}</option>
                                    <option value="none">{t('No group (independent extra)')}</option>
                                    {groups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
                                </SelectInput>
                            </div>
                            <div>
                                <label htmlFor="q" className="block text-sm font-medium text-gray-700">{t('Search')}</label>
                                <TextInput id="q" className="mt-1 block w-48 text-sm" placeholder={t('Search by name')} value={q} onChange={(e) => setQ(e.target.value)} />
                            </div>
                            <SecondaryButton type="submit">{t('Filter')}</SecondaryButton>
                            <label className="flex items-center gap-2 pb-2 text-sm text-gray-700">
                                <Checkbox checked={filters.entered} onChange={(e) => visit({ entered: e.target.checked ? 1 : 0 })} />
                                {t('Only items with an entry for this model')}
                            </label>
                        </form>

                        <div role="status" aria-live="polite" className="min-h-5 text-sm text-brand-700">{message}</div>

                        {items.length === 0 ? (
                            <p className="text-sm text-gray-600">{t('No items match the filters.')}</p>
                        ) : (
                            <div className="max-h-[70vh] overflow-auto rounded-md border border-gray-200 bg-white">
                                <table className="min-w-full border-separate border-spacing-0 text-sm">
                                    <caption className="sr-only">{t('Equipment matrix')}</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col" className="sticky left-0 top-0 z-30 min-w-56 border-b border-gray-200 bg-surface px-3 py-2 text-left text-xs font-semibold uppercase text-gray-500">
                                                {t('Item (equipment)')}
                                            </th>
                                            {trims.map((trim) => (
                                                <th key={trim.id} scope="col" className="sticky top-0 z-20 border-b border-gray-200 bg-surface px-3 py-2 text-center">
                                                    <span className={trim.is_active ? 'text-ink' : 'italic text-gray-400'}>{trim.name}</span>
                                                    <span
                                                        className="block text-xs font-normal text-gray-500"
                                                        title={t(':standard standard, :optional extra', { standard: trim.standard_count, optional: trim.optional_count })}
                                                    >
                                                        S{trim.standard_count} · O{trim.optional_count}
                                                    </span>
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    {byCategory.map(({ category, rows }) => (
                                        <tbody key={category}>
                                            <tr>
                                                <th scope="colgroup" colSpan={columns} className="sticky left-0 border-b border-gray-200 bg-brand-50 px-3 py-1 text-left text-xs font-semibold uppercase text-brand-800">
                                                    {categoryLabel(category)}
                                                </th>
                                            </tr>
                                            {rows.map((item) => (
                                                <tr key={item.id} className={item.locked ? 'bg-gray-50 text-gray-400' : ''}>
                                                    <th scope="row" className={`sticky left-0 z-10 border-b border-gray-100 ${item.locked ? 'bg-gray-50' : 'bg-white'} px-3 py-1 text-left font-normal`}>
                                                        <span className="flex items-center gap-2">
                                                            {item.swatch_hex && <span aria-hidden="true" className="inline-block h-3 w-3 rounded-full border border-gray-300" style={{ backgroundColor: item.swatch_hex }} />}
                                                            <span>
                                                                {item.name}
                                                                {item.group_name && <span className="block text-xs text-gray-500">{item.group_name}{item.selection === 'single' ? ` · ${t('option.selection.single')}` : ''}</span>}
                                                                {item.locked && <span className="block text-xs italic text-yellow-800">{item.locked === 'item' ? t('Not offered: the item is inactive.') : t('Not offered: the option group is inactive.')}</span>}
                                                            </span>
                                                        </span>
                                                    </th>
                                                    {trims.map((trim) => (
                                                        <td key={trim.id} className="border-b border-gray-100 p-0">
                                                            <CellButton
                                                                trim={trim}
                                                                item={item}
                                                                entry={index.get(cellKey(trim.id, item.id))}
                                                                onOpen={(openTrim, openItem, openEntry) => { setMessage(null); setDialog({ trim: openTrim, item: openItem, entry: openEntry, entries }); }}
                                                            />
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    ))}
                                </table>
                            </div>
                        )}
                    </>
                )}
            </div>

            {dialog && (
                <CellDialog
                    key={`${dialog.trim.id}:${dialog.item.id}`}
                    ctx={dialog}
                    modelId={selectedModelId}
                    rateBp={vatRateBp}
                    onClose={() => setDialog(null)}
                    onSaved={closeAndReload}
                />
            )}
        </CatalogLayout>
    );
}
