import OfferPdfActions from '@/Components/OfferPdfActions';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { formatMoneyOrDash, formatRateBp } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ offers, filters, perPageOptions, isAdmin }) {
    const [q, setQ] = useState(filters.q ?? '');

    const load = (params) => router.get(route('offers.index'), params, { preserveState: true, replace: true });

    const search = (e) => {
        e.preventDefault();
        load({ q: q || undefined, per_page: filters.per_page });
    };

    const reset = () => {
        setQ('');
        load({ per_page: filters.per_page });
    };

    const searching = Boolean(filters.q);
    const columns = isAdmin ? 10 : 9;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-ink">{t('Offers')}</h2>}>
            <Head title={t('Offers')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    <form onSubmit={search} className="flex flex-col gap-3 bg-white p-4 shadow sm:flex-row sm:items-end sm:rounded-lg">
                        <div className="flex-1">
                            <label htmlFor="q" className="block text-sm font-medium text-gray-700">{t('Search')}</label>
                            <TextInput
                                id="q"
                                type="search"
                                className="mt-1 block w-full"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                maxLength={100}
                                placeholder={isAdmin ? t('Offer number, client name, PIB or note') : t('Offer number, name or note')}
                            />
                        </div>

                        <div>
                            <label htmlFor="per_page" className="block text-sm font-medium text-gray-700">{t('Rows per page')}</label>
                            <SelectInput
                                id="per_page"
                                className="mt-1 block"
                                value={filters.per_page}
                                onChange={(e) => load({ q: filters.q || undefined, per_page: e.target.value })}
                            >
                                {perPageOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                            </SelectInput>
                        </div>

                        <div className="flex gap-2">
                            <PrimaryButton type="submit">{t('Search')}</PrimaryButton>
                            <SecondaryButton onClick={reset}>{t('Reset')}</SecondaryButton>
                        </div>
                    </form>

                    <div className="overflow-x-auto bg-white shadow sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                            <thead className="bg-surface text-xs uppercase text-gray-500">
                                <tr>
                                    <th className="px-3 py-2">{t('Offer number')}</th>
                                    {isAdmin && <th className="px-3 py-2">{t('Client')}</th>}
                                    <th className="px-3 py-2">{t('Date')}</th>
                                    <th className="px-3 py-2 text-right">{t('VAT (%)')}</th>
                                    <th className="px-3 py-2 text-right">{t('Amount without VAT')}</th>
                                    <th className="px-3 py-2 text-right">{t('VAT amount')}</th>
                                    <th className="px-3 py-2 text-right">{t('Total')}</th>
                                    <th className="px-3 py-2 text-right">{t('Items')}</th>
                                    <th className="px-3 py-2">{t('Note')}</th>
                                    <th className="px-3 py-2">{t('Actions')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {offers.data.length === 0 && (
                                    <tr>
                                        <td colSpan={columns} className="px-3 py-10 text-center text-gray-500">
                                            {searching ? (
                                                <p>{t('No offers match the search.')}</p>
                                            ) : (
                                                <div className="space-y-3">
                                                    <p>{isAdmin ? t('No offers have been made yet.') : t('You have no offers yet.')}</p>
                                                    {!isAdmin && (
                                                        <Link
                                                            href={route('offers.create')}
                                                            className="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2"
                                                        >
                                                            {t('New offer')}
                                                        </Link>
                                                    )}
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                )}
                                {offers.data.map((offer) => (
                                    <tr key={offer.id} className="align-top">
                                        <td className="whitespace-nowrap px-3 py-2 font-medium text-ink">
                                            <Link href={route('offers.show', offer.id)} className="text-brand-700 hover:text-brand-800">{offer.number}</Link>
                                        </td>
                                        {isAdmin && <td className="px-3 py-2">{offer.client_name}</td>}
                                        <td className="whitespace-nowrap px-3 py-2">{offer.offer_date}</td>
                                        <td className="px-3 py-2 text-right">{formatRateBp(offer.vat_rate_bp)}</td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right">{formatMoneyOrDash(offer.total_net_cents)}</td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right">{formatMoneyOrDash(offer.vat_cents)}</td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right font-semibold text-ink">{formatMoneyOrDash(offer.total_gross_cents)}</td>
                                        <td className="px-3 py-2 text-right">{offer.items_count}</td>
                                        <td className="max-w-xs px-3 py-2 text-gray-600">{offer.note ?? ''}</td>
                                        <td className="whitespace-nowrap px-3 py-2">
                                            <div className="flex items-center gap-3">
                                                <Link href={route('offers.show', offer.id)} className="font-medium text-brand-700 hover:text-brand-800">{t('View')}</Link>
                                                <OfferPdfActions offer={offer} compact />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination paginator={offers} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
