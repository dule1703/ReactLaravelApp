import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import RevealValue from '@/Components/RevealValue';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const typeLabel = (type) =>
    type === 'company' ? t('Company (legal entity)') : t('Individual (natural person)');

export default function Index({ clients, filters, perPageOptions }) {
    const [q, setQ] = useState(filters.q ?? '');
    const [toDelete, setToDelete] = useState(null);
    const [deleting, setDeleting] = useState(false);

    const load = (params) =>
        router.get(route('clients.index'), params, { preserveState: true, replace: true });

    const search = (e) => {
        e.preventDefault();
        load({ q: q || undefined, per_page: filters.per_page });
    };

    const reset = () => {
        setQ('');
        load({ per_page: filters.per_page });
    };

    const confirmDelete = () => {
        setDeleting(true);
        router.delete(route('clients.destroy', toDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                setToDelete(null);
            },
        });
    };

    const firstRow = clients.from ?? 1;

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-ink">{t('Clients')}</h2>}
        >
            <Head title={t('Clients')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    <form
                        onSubmit={search}
                        className="flex flex-col gap-3 bg-white p-4 shadow sm:flex-row sm:items-end sm:rounded-lg"
                    >
                        <div className="flex-1">
                            <label htmlFor="q" className="block text-sm font-medium text-gray-700">
                                {t('Search')}
                            </label>
                            <TextInput
                                id="q"
                                type="search"
                                className="mt-1 block w-full"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                maxLength={100}
                                placeholder={t('Name, email, PIB, city, address, postal code or exact JMBG')}
                            />
                        </div>

                        <div>
                            <label htmlFor="per_page" className="block text-sm font-medium text-gray-700">
                                {t('Rows per page')}
                            </label>
                            <SelectInput
                                id="per_page"
                                className="mt-1 block"
                                value={filters.per_page}
                                onChange={(e) => load({ q: filters.q || undefined, per_page: e.target.value })}
                            >
                                {perPageOptions.map((option) => (
                                    <option key={option} value={option}>
                                        {option}
                                    </option>
                                ))}
                            </SelectInput>
                        </div>

                        <div className="flex gap-2">
                            <PrimaryButton type="submit">{t('Search')}</PrimaryButton>
                            <SecondaryButton onClick={reset}>{t('Reset')}</SecondaryButton>
                            <Link
                                href={route('clients.create')}
                                className="inline-flex items-center rounded-md border border-brand-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-brand-700 hover:bg-brand-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2"
                            >
                                {t('New client')}
                            </Link>
                        </div>
                    </form>

                    <div className="overflow-x-auto bg-white shadow sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                            <thead className="bg-surface text-xs uppercase text-gray-500">
                                <tr>
                                    <th className="px-3 py-2">{t('No.')}</th>
                                    <th className="px-3 py-2">{t('Client')}</th>
                                    <th className="px-3 py-2">{t('Email')}</th>
                                    <th className="px-3 py-2">{t('Type')}</th>
                                    <th className="px-3 py-2">JMBG</th>
                                    <th className="px-3 py-2">PIB</th>
                                    <th className="px-3 py-2">{t('Address')}</th>
                                    <th className="px-3 py-2">{t('Postal code')}</th>
                                    <th className="px-3 py-2">{t('City')}</th>
                                    <th className="px-3 py-2">{t('Country')}</th>
                                    <th className="px-3 py-2">{t('Registered')}</th>
                                    <th className="px-3 py-2">{t('Actions')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {clients.data.length === 0 && (
                                    <tr>
                                        <td colSpan={12} className="px-3 py-8 text-center text-gray-500">
                                            {filters.q ? (
                                                <p>{t('No clients match the search.')}</p>
                                            ) : (
                                                <div className="space-y-3">
                                                    <p>{t('No clients have registered yet.')}</p>
                                                    <Link href={route('clients.create')} className="font-semibold text-brand-700 underline">
                                                        {t('Add the first client')}
                                                    </Link>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                )}
                                {clients.data.map((client, index) => (
                                    <tr key={client.id} className="align-top">
                                        <td className="px-3 py-2">{firstRow + index}</td>
                                        <td className="px-3 py-2 font-medium text-ink">{client.name}</td>
                                        <td className="px-3 py-2">{client.email}</td>
                                        <td className="px-3 py-2">{typeLabel(client.type)}</td>
                                        <td className="px-3 py-2">
                                            <RevealValue clientId={client.id} field="jmbg" masked={client.jmbg_masked} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <RevealValue clientId={client.id} field="pib" masked={client.pib_masked} />
                                        </td>
                                        <td className="px-3 py-2">{client.address}</td>
                                        <td className="px-3 py-2">{client.postal_code}</td>
                                        <td className="px-3 py-2">{client.city}</td>
                                        <td className="px-3 py-2">{client.country}</td>
                                        <td className="whitespace-nowrap px-3 py-2">{client.registered_at}</td>
                                        <td className="whitespace-nowrap px-3 py-2">
                                            <Link
                                                href={route('clients.edit', client.id)}
                                                className="me-3 font-medium text-brand-700 hover:text-brand-800"
                                            >
                                                {t('Edit')}
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => setToDelete(client)}
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

                    <Pagination paginator={clients} />
                </div>
            </div>

            <Modal show={toDelete !== null} onClose={() => setToDelete(null)} maxWidth="md">
                {toDelete && (
                    <div className="p-6">
                        <h3 className="text-lg font-medium text-ink">{t('Delete client')}</h3>
                        <p className="mt-2 text-sm text-gray-600">
                            {t('Delete :name? The account and the profile are removed. The activity log is kept.', {
                                name: toDelete.name,
                            })}
                        </p>
                        <div className="mt-6 flex justify-end gap-3">
                            <SecondaryButton onClick={() => setToDelete(null)}>{t('Cancel')}</SecondaryButton>
                            <DangerButton onClick={confirmDelete} disabled={deleting}>
                                {t('Delete')}
                            </DangerButton>
                        </div>
                    </div>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
