import OfferStatusBadge from '@/Components/OfferStatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { formatMoneyOrDash } from '@/lib/money';
import { Head, Link } from '@inertiajs/react';

export default function Dashboard({ name, profile_missing: profileMissing, recent_offers: recentOffers }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-ink">
                    {t('Dashboard')}
                </h2>
            }
        >
            <Head title={t('Dashboard')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white p-4 shadow-sm sm:p-6">
                        <h3 className="min-w-0 break-words text-lg font-medium text-ink">
                            {t('Welcome, :name', { name })}
                        </h3>
                        <Link
                            href={route('offers.create')}
                            className="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2"
                        >
                            {t('New offer')}
                        </Link>
                    </div>

                    {profileMissing.length > 0 && (
                        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-danger bg-white p-4">
                            <p className="text-sm text-ink">{t('Complete your profile before you save an offer: it is printed on the offer.')}</p>
                            <Link href={route('client-profile.edit')} className="text-sm font-semibold text-brand-700 underline">
                                {t('Complete the profile')}
                            </Link>
                        </div>
                    )}

                    <section className="rounded-lg bg-white p-4 shadow-sm sm:p-6">
                        <div className="mb-3 flex items-baseline justify-between gap-2">
                            <h3 className="text-lg font-medium text-ink">{t('Your latest offers')}</h3>
                            {recentOffers.length > 0 && (
                                <Link href={route('offers.index')} className="text-sm text-brand-700 hover:underline">
                                    {t('All offers')}
                                </Link>
                            )}
                        </div>

                        {recentOffers.length === 0 ? (
                            <p className="text-sm text-gray-600">
                                {t('You have no offers yet.')}{' '}
                                <Link href={route('offers.create')} className="font-semibold text-brand-700 underline">
                                    {t('Make your first offer')}
                                </Link>
                            </p>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {recentOffers.map((offer) => (
                                    <li key={offer.id} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2 text-sm">
                                        <div>
                                            <Link href={route('offers.show', offer.id)} className="font-semibold text-brand-700 hover:underline">
                                                {offer.number}
                                            </Link>{' '}
                                            <OfferStatusBadge offer={offer} />
                                            <div className="text-xs text-gray-500">{offer.offer_date}</div>
                                        </div>
                                        <div className="font-semibold text-ink">{formatMoneyOrDash(offer.total_gross_cents)}</div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
