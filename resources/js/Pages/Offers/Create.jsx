import ClientPicker from '@/Components/Configurator/ClientPicker';
import ConflictPanel from '@/Components/Configurator/ConflictPanel';
import EquipmentChoices from '@/Components/Configurator/EquipmentChoices';
import ItemList, { describeVersion } from '@/Components/Configurator/ItemList';
import Totals from '@/Components/Configurator/Totals';
import useVersionCatalog from '@/Components/Configurator/useVersionCatalog';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    buildItem,
    configuratorReducer,
    createState,
    errorMessages,
    hasUnsavedItems,
    optionIndex,
    parseQuantity,
    saveBlocker,
    saveBody,
    totalsFromConflict,
    totalsOf,
} from '@/lib/configurator';
import { t } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useReducer, useRef, useState } from 'react';

const EMPTY_TOTALS = { items: [], total_net_cents: 0, vat_cents: 0, total_gross_cents: 0 };

function Step({ number, title, children }) {
    return (
        <section className="rounded-lg bg-white p-4 shadow sm:p-6">
            <h3 className="mb-4 flex items-center gap-3 text-base font-semibold text-ink">
                <span aria-hidden="true" className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-600 text-sm text-white">{number}</span>
                {title}
            </h3>
            {children}
        </section>
    );
}

export default function Create({ models, vatRateBp, profileMissing, limits, isAdmin = false }) {
    const catalog = useVersionCatalog();
    const [state, dispatch] = useReducer(configuratorReducer, undefined, createState);

    const [modelId, setModelId] = useState(null);
    const [trims, setTrims] = useState([]);
    const [detail, setDetail] = useState(null);
    const [chosen, setChosen] = useState([]);
    const [quantity, setQuantity] = useState('1');
    const [editingUid, setEditingUid] = useState(null);
    const [note, setNote] = useState('');
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [problems, setProblems] = useState([]);
    const [conflict, setConflict] = useState(null);
    // An admin makes the offer for a chosen client (a row of the client search: id of the PROFILE, name, city, email, complete).
    const [client, setClient] = useState(null);
    const [clientError, setClientError] = useState(null);
    const saved = useRef(false);

    const { items } = state;
    const profileIncomplete = profileMissing.length > 0;
    const stepOffset = isAdmin ? 1 : 0;
    const calculation = useMemo(() => totalsOf(items, vatRateBp), [items, vatRateBp]);
    const totals = calculation.totals ?? EMPTY_TOTALS;
    const parsedQuantity = parseQuantity(quantity, limits.maxQuantity);
    const atItemLimit = editingUid === null && items.length >= limits.maxItems;

    // Warn before leaving the page with items that are not saved yet.
    useEffect(() => {
        if (!hasUnsavedItems(items)) {
            return undefined;
        }

        const beforeUnload = (event) => {
            if (!saved.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        const removeInertiaListener = router.on('before', (event) => {
            if (!saved.current && !window.confirm(t('You have unsaved items in this offer. Leave the page anyway?'))) {
                event.preventDefault();
            }
        });

        window.addEventListener('beforeunload', beforeUnload);

        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            removeInertiaListener();
        };
    }, [items]);

    const fail = (error) => setProblems(errorMessages(error, t('The data could not be loaded. Try again.')));

    const resetSelection = () => {
        setDetail(null);
        setChosen([]);
        setQuantity('1');
        setEditingUid(null);
    };

    const pickModel = async (id) => {
        setModelId(id);
        resetSelection();
        setTrims([]);
        setProblems([]);
        setLoading(true);

        try {
            setTrims((await catalog.versions(id)).trims);
        } catch (error) {
            fail(error);
        } finally {
            setLoading(false);
        }
    };

    const pickVersion = async (versionId, keep = []) => {
        setProblems([]);
        setLoading(true);

        try {
            const loaded = await catalog.detail(versionId, keep.length > 0);
            const available = optionIndex(loaded);

            setDetail(loaded);
            setChosen(keep.filter((id) => available.has(id)));
        } catch (error) {
            fail(error);
        } finally {
            setLoading(false);
        }
    };

    const saveModel = () => {
        const item = buildItem(detail, chosen, parsedQuantity, modelId);

        dispatch(editingUid === null ? { type: 'add', item } : { type: 'replace', uid: editingUid, item });
        resetSelection();
        setConflict(null);
        setProblems([]);
    };

    const editItem = async (item) => {
        setModelId(item.model_id);
        setEditingUid(item.uid);
        setQuantity(String(item.quantity));
        setProblems([]);
        setLoading(true);

        try {
            const [versions, loaded] = await Promise.all([catalog.versions(item.model_id), catalog.detail(item.version_id, true)]);
            const available = optionIndex(loaded);

            setTrims(versions.trims);
            setDetail(loaded);
            setChosen(item.option_ids.filter((id) => available.has(id)));
        } catch (error) {
            fail(error);
        } finally {
            setLoading(false);
        }
    };

    const removeItem = (item) => {
        dispatch({ type: 'remove', uid: item.uid });

        if (editingUid === item.uid) {
            resetSelection();
        }

        setConflict(null);
    };

    const submit = async (expectedTotals) => {
        setSaving(true);
        setProblems([]);

        try {
            const { data } = await window.axios.post(route('offers.store'), saveBody(items, note, expectedTotals, isAdmin ? client?.id ?? null : null));

            saved.current = true;
            router.visit(data.redirect);
        } catch (error) {
            if (error.response?.status === 409) {
                setConflict({
                    seen: expectedTotals,
                    fresh: totalsFromConflict(error.response.data),
                    message: error.response.data.message,
                    rateChanged: error.response.data.vat_rate_bp !== vatRateBp,
                });
            } else {
                setConflict(null);
                // The message about the client is shown under the client picker, the rest below the offer.
                setClientError(error.response?.data?.errors?.client_id?.[0] ?? null);
                setProblems(errorMessages(error, t('Saving failed. Try again.'), ['client_id']));
            }
        } finally {
            setSaving(false);
        }
    };

    const blocker = saveBlocker({
        isAdmin,
        profileIncomplete,
        client,
        itemCount: items.length,
        calculationError: calculation.error ?? null,
        editing: editingUid !== null,
        saving,
    });
    const canSave = blocker === null;
    const unitSummary = detail && (
        <p className="text-sm text-gray-700">
            {t('Price of one vehicle')}: <strong>{formatMoney(detail.version.price_cents + chosen.reduce((sum, id) => sum + (optionIndex(detail).get(id)?.price_cents ?? 0), 0))}</strong>{' '}
            <span className="text-gray-500">({t('without VAT')})</span>
        </p>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-ink">{t('New offer')}</h2>}>
            <Head title={t('New offer')} />

            <div className="py-8">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {profileIncomplete && (
                        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-danger bg-white p-4">
                            <p className="text-sm text-ink">{t('Complete your profile before you save an offer: it is printed on the offer.')}</p>
                            <Link href={route('client-profile.edit')} className="text-sm font-semibold text-brand-700 underline">{t('Complete the profile')}</Link>
                        </div>
                    )}

                    <div className="grid gap-6 lg:grid-cols-3">
                        <div className="space-y-6 lg:col-span-2">
                            {isAdmin && (
                                <Step number={1} title={t('Client for the offer')}>
                                    <ClientPicker
                                        selected={client}
                                        onSelect={(chosen) => {
                                            setClient(chosen);
                                            setClientError(null);
                                            setConflict(null);
                                        }}
                                        serverError={clientError}
                                    />
                                </Step>
                            )}

                            <Step number={1 + stepOffset} title={t('Model')}>
                                {models.length === 0 ? (
                                    <p className="text-sm text-gray-500">
                                        {t('No models are available for an offer right now.')}{' '}
                                        {isAdmin ? (
                                            <Link href={route('catalog.models.index')} className="font-semibold text-brand-700 underline">{t('Open the catalog')}</Link>
                                        ) : (
                                            t('Please contact the dealer.')
                                        )}
                                    </p>
                                ) : (
                                    <ul className="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                                        {models.map((model) => (
                                            <li key={model.id}>
                                                <button
                                                    type="button"
                                                    onClick={() => pickModel(model.id)}
                                                    aria-pressed={modelId === model.id}
                                                    className={`flex h-full w-full flex-col items-stretch overflow-hidden rounded-lg border text-left transition focus:outline-none focus:ring-2 focus:ring-brand-500 ${modelId === model.id ? 'border-brand-600 ring-2 ring-brand-500' : 'border-gray-200 hover:border-brand-400'}`}
                                                >
                                                    {model.image_url ? (
                                                        <img src={model.image_url} alt="" className="h-28 w-full bg-surface object-cover" />
                                                    ) : (
                                                        <div aria-hidden="true" className="h-28 w-full bg-surface" />
                                                    )}
                                                    <span className="p-3 font-semibold text-ink">{model.name}</span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Step>

                            {modelId !== null && (
                                <Step number={2 + stepOffset} title={t('Trim, engine and transmission')}>
                                    {loading && trims.length === 0 && <p className="text-sm text-gray-500">{t('Loading…')}</p>}
                                    <div className="space-y-5">
                                        {trims.map((trim) => (
                                            <fieldset key={trim.id}>
                                                <legend className="mb-2 text-sm font-semibold text-ink">{trim.name}</legend>
                                                <div className="space-y-2">
                                                    {trim.versions.map((version) => (
                                                        <label key={version.id} className="flex cursor-pointer items-center justify-between gap-3 rounded-md border border-gray-200 px-3 py-2 hover:bg-surface has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                                                            <span className="flex items-center gap-2 text-sm">
                                                                <input
                                                                    type="radio"
                                                                    name="version"
                                                                    className="text-brand-600 focus:ring-brand-500"
                                                                    checked={detail?.version.id === version.id}
                                                                    onChange={() => pickVersion(version.id)}
                                                                />
                                                                {describeVersion(version)}
                                                            </span>
                                                            <span className="whitespace-nowrap text-sm font-medium text-ink">{formatMoney(version.price_cents)}</span>
                                                        </label>
                                                    ))}
                                                </div>
                                            </fieldset>
                                        ))}
                                    </div>
                                </Step>
                            )}

                            {detail && (
                                <>
                                    <Step number={3 + stepOffset} title={t('Equipment')}>
                                        <EquipmentChoices detail={detail} chosen={chosen} onChange={setChosen} rateBp={vatRateBp} />
                                    </Step>

                                    <Step number={4 + stepOffset} title={t('Number of vehicles')}>
                                        <div className="flex flex-wrap items-end gap-4">
                                            <div>
                                                <label htmlFor="quantity" className="block text-sm font-medium text-gray-700">{t('Number of vehicles')}</label>
                                                <TextInput
                                                    id="quantity"
                                                    inputMode="numeric"
                                                    className="mt-1 block w-24 text-sm"
                                                    value={quantity}
                                                    onChange={(e) => setQuantity(e.target.value)}
                                                    aria-invalid={parsedQuantity === null}
                                                    aria-describedby="quantity-hint"
                                                />
                                                <p id="quantity-hint" className={`mt-1 text-xs ${parsedQuantity === null ? 'text-danger' : 'text-gray-500'}`}>
                                                    {t('A whole number from 1 to :max.', { max: limits.maxQuantity })}
                                                </p>
                                            </div>
                                            <div className="flex-1">{unitSummary}</div>
                                        </div>

                                        {atItemLimit && <p role="alert" className="mt-3 text-sm text-danger">{t('An offer can have at most :max models.', { max: limits.maxItems })}</p>}

                                        <div className="mt-4 flex flex-wrap gap-2">
                                            <PrimaryButton type="button" disabled={parsedQuantity === null || atItemLimit || loading} onClick={saveModel}>
                                                {editingUid === null ? t('Save model') : t('Update model')}
                                            </PrimaryButton>
                                            {editingUid !== null && <SecondaryButton onClick={resetSelection}>{t('Cancel')}</SecondaryButton>}
                                        </div>
                                    </Step>
                                </>
                            )}
                        </div>

                        <aside className="space-y-4 lg:sticky lg:top-4 lg:self-start">
                            <section className="rounded-lg bg-white p-4 shadow sm:p-6" aria-labelledby="current-offer">
                                <h3 id="current-offer" className="mb-3 text-base font-semibold text-ink">{t('Current offer')}</h3>
                                <ItemList items={items} lines={calculation.totals?.items} rateBp={vatRateBp} editingUid={editingUid} onEdit={editItem} onRemove={removeItem} disabled={saving} />

                                {items.length > 0 && (
                                    <>
                                        <div className="mt-4">
                                            <label htmlFor="note" className="block text-sm font-medium text-gray-700">{t('Note')}</label>
                                            <textarea
                                                id="note"
                                                rows={3}
                                                maxLength={limits.noteMax}
                                                value={note}
                                                onChange={(e) => setNote(e.target.value)}
                                                className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
                                            />
                                        </div>

                                        <div className="mt-4 border-t border-gray-200 pt-4">
                                            <Totals totals={totals} rateBp={vatRateBp} error={calculation.error} />
                                        </div>
                                    </>
                                )}

                                {problems.length > 0 && (
                                    <ul role="alert" className="mt-4 list-inside list-disc text-sm text-danger">
                                        {problems.map((message, index) => <li key={index}>{message}</li>)}
                                    </ul>
                                )}

                                {conflict && (
                                    <div className="mt-4">
                                        <ConflictPanel conflict={conflict} processing={saving} onConfirm={() => submit(conflict.fresh)} onCancel={() => setConflict(null)} />
                                    </div>
                                )}

                                {!conflict && (
                                    <div className="mt-4">
                                        <PrimaryButton type="button" className="w-full justify-center" disabled={!canSave} onClick={() => submit(totals)}>
                                            {saving ? t('Saving…') : t('Save offer')}
                                        </PrimaryButton>
                                        {editingUid !== null && <p className="mt-2 text-xs text-gray-500">{t('Finish or cancel editing the model before you save the offer.')}</p>}
                                        {blocker === 'client' && <p className="mt-2 text-xs text-gray-500">{t('Choose a client to make the offer for.')}</p>}
                                        {blocker === 'client_incomplete' && <p className="mt-2 text-xs text-danger">{t('The profile of this client is incomplete. Complete it to make an offer.')}</p>}
                                    </div>
                                )}
                            </section>
                        </aside>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
