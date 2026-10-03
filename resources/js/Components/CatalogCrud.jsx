import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { t } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

// Add / edit form inside a modal. `Form` renders the fields; the server validates and the
// errors come back per field. Re-mounted per row (key), so every open starts from that row.
function EntityForm({ resource, row, entity, initialData, Form, formContext, usageWarning, onClose }) {
    const editing = row !== null;
    const { data, setData, post, patch, errors, processing } = useForm(initialData(row));
    const warning = editing ? usageWarning?.(row) : null;

    const submit = (e) => {
        e.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) {
            patch(route(`${resource}.update`, row.id), options);
        } else {
            post(route(`${resource}.store`), options);
        }
    };

    return (
        <form onSubmit={submit} className="p-6">
            <h3 className="text-lg font-medium text-ink">
                {editing ? t('Edit: :entity', { entity }) : t('Add: :entity', { entity })}
            </h3>

            {warning && (
                <p role="note" className="mt-3 rounded-md border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-900">
                    {warning}
                </p>
            )}

            <div className="mt-4 space-y-4">
                <Form data={data} setData={setData} errors={errors} editing={editing} row={row} context={formContext} />
            </div>

            <div className="mt-6 flex justify-end gap-3">
                <SecondaryButton onClick={onClose}>{t('Cancel')}</SecondaryButton>
                <PrimaryButton disabled={processing}>{t('Save')}</PrimaryButton>
            </div>
        </form>
    );
}

function ConfirmModal({ show, title, text, confirmLabel, danger = false, busy = false, onConfirm, onClose }) {
    const Button = danger ? DangerButton : PrimaryButton;

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <div className="p-6">
                <h3 className="text-lg font-medium text-ink">{title}</h3>
                <p className="mt-2 text-sm text-gray-600">{text}</p>
                <div className="mt-6 flex justify-end gap-3">
                    <SecondaryButton onClick={onClose}>{t('Cancel')}</SecondaryButton>
                    <Button onClick={onConfirm} disabled={busy}>
                        {confirmLabel}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}

/**
 * List + add/edit modal + activate/deactivate + delete for one catalog entity.
 *
 * columns: [{ header, cell: (row) => node }]. `rowNote(row)` adds a note under the status badge.
 * Deactivating asks for confirmation when it makes offered versions unavailable
 * (row.available_versions, counted on the server with Version::available()). Deleting a row
 * that is in use is refused by the server with the dependency counts (shown as a flash error).
 */
export default function CatalogCrud({
    resource,
    entity,
    items,
    filters,
    columns,
    Form,
    formContext,
    initialData,
    usageWarning,
    rowNote,
}) {
    const [q, setQ] = useState(filters.q ?? '');
    const [form, setForm] = useState(null); // { row } | null
    const [deactivating, setDeactivating] = useState(null);
    const [removing, setRemoving] = useState(null);
    const [busy, setBusy] = useState(false);

    const search = (e) => {
        e.preventDefault();
        router.get(route(`${resource}.index`), q ? { q } : {}, { preserveState: true, replace: true });
    };

    const setActive = (row, isActive, onFinish) =>
        router.patch(
            route(`${resource}.active`, row.id),
            { is_active: isActive },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); onFinish?.(); } },
        );

    const toggle = (row) => {
        if (row.is_active && row.available_versions > 0) {
            setDeactivating(row);
        } else {
            setActive(row, !row.is_active);
        }
    };

    const remove = () => {
        router.delete(route(`${resource}.destroy`, removing.id), {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => { setBusy(false); setRemoving(null); },
        });
    };

    return (
        <>
            <div className="flex flex-col gap-3 bg-white p-4 shadow sm:flex-row sm:items-end sm:justify-between sm:rounded-lg">
                <form onSubmit={search} className="flex flex-1 flex-col gap-2 sm:flex-row sm:items-end">
                    <div className="flex-1">
                        <label htmlFor="q" className="block text-sm font-medium text-gray-700">
                            {t('Search by name')}
                        </label>
                        <TextInput
                            id="q"
                            type="search"
                            className="mt-1 block w-full"
                            value={q}
                            maxLength={100}
                            onChange={(e) => setQ(e.target.value)}
                        />
                    </div>
                    <PrimaryButton type="submit">{t('Search')}</PrimaryButton>
                </form>
                <PrimaryButton type="button" onClick={() => setForm({ row: null })}>
                    {t('Add: :entity', { entity })}
                </PrimaryButton>
            </div>

            <div className="overflow-x-auto bg-white shadow sm:rounded-lg">
                <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                    <thead className="bg-surface text-xs uppercase text-gray-500">
                        <tr>
                            {columns.map((column) => (
                                <th key={column.header} className="px-3 py-2">
                                    {column.header}
                                </th>
                            ))}
                            <th className="px-3 py-2">{t('Status')}</th>
                            <th className="px-3 py-2">{t('Actions')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {items.data.length === 0 && (
                            <tr>
                                <td colSpan={columns.length + 2} className="px-3 py-8 text-center text-gray-500">
                                    {t('Nothing found.')}
                                </td>
                            </tr>
                        )}
                        {items.data.map((row) => (
                            <tr key={row.id} className={`align-top ${row.is_active ? '' : 'text-gray-400'}`}>
                                {columns.map((column) => (
                                    <td key={column.header} className="px-3 py-2">
                                        {column.cell(row)}
                                    </td>
                                ))}
                                <td className="px-3 py-2">
                                    <span className={row.is_active ? 'font-medium text-brand-700' : 'italic'}>
                                        {row.is_active ? t('Active') : t('Inactive')}
                                    </span>
                                    {rowNote?.(row)}
                                </td>
                                <td className="whitespace-nowrap px-3 py-2">
                                    <button
                                        type="button"
                                        onClick={() => setForm({ row })}
                                        className="me-3 font-medium text-brand-700 hover:text-brand-800"
                                    >
                                        {t('Edit')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => toggle(row)}
                                        disabled={busy}
                                        className="me-3 font-medium text-ink hover:underline disabled:opacity-50"
                                    >
                                        {row.is_active ? t('Deactivate') : t('Activate')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setRemoving(row)}
                                        className="font-medium text-danger hover:underline"
                                    >
                                        {t('Delete')}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {items.last_page > 1 && <Pagination paginator={items} />}

            <Modal show={form !== null} onClose={() => setForm(null)} maxWidth="lg">
                {form && (
                    <EntityForm
                        key={form.row?.id ?? 'new'}
                        resource={resource}
                        row={form.row}
                        entity={entity}
                        initialData={initialData}
                        Form={Form}
                        formContext={formContext}
                        usageWarning={usageWarning}
                        onClose={() => setForm(null)}
                    />
                )}
            </Modal>

            <ConfirmModal
                show={deactivating !== null}
                title={t('Deactivate: :name', { name: deactivating?.name ?? '' })}
                text={t('Deactivating makes versions unavailable: :count. They stay in the database and come back when you activate it again.', {
                    count: deactivating?.available_versions ?? 0,
                })}
                confirmLabel={t('Deactivate')}
                busy={busy}
                onConfirm={() => setActive(deactivating, false, () => setDeactivating(null))}
                onClose={() => setDeactivating(null)}
            />

            <ConfirmModal
                show={removing !== null}
                danger
                title={t('Delete: :name', { name: removing?.name ?? '' })}
                text={t('A row that is in use cannot be deleted; deactivate it instead. Delete :name?', {
                    name: removing?.name ?? '',
                })}
                confirmLabel={t('Delete')}
                busy={busy}
                onConfirm={remove}
                onClose={() => setRemoving(null)}
            />
        </>
    );
}
