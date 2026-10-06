import AdminLinkCard from '@/Components/AdminLinkCard';
import OfferStatusBadge from '@/Components/OfferStatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { activityActionLabel } from '@/lib/activity';
import { t } from '@/lib/i18n';
import { formatMoneyOrDash } from '@/lib/money';
import { Head, Link } from '@inertiajs/react';

const SHORTCUTS = [
    { routeName: 'prices.index', title: 'Prices', description: 'Update prices in bulk' },
    { routeName: 'catalog.models.index', title: 'Catalog', description: 'Car models, trims, engines, equipment' },
    { routeName: 'clients.index', title: 'Clients', description: 'Client accounts and profiles' },
    { routeName: 'offers.create', title: 'New offer', description: 'Create an offer, for a client too' },
    { routeName: 'admin.activity-log', title: 'Activity log', description: 'Who did what and when' },
    { routeName: 'issuer.edit', title: 'Offer issuer', description: 'Dealer details printed in the header of the offer PDF' },
];

const COUNTS = [
    { key: 'clients', label: 'Clients registered' },
    { key: 'active_offers', label: 'dashboard.active_offers' },
    { key: 'withdrawn_offers', label: 'dashboard.withdrawn_offers' },
    { key: 'offers_last_30_days', label: 'Offers in the last 30 days' },
];

function Actor({ activity }) {
    if (activity.actor_type === 'system') {
        return <span className="italic text-gray-500">{t('System')}</span>;
    }

    if (activity.actor_type === 'guest') {
        return <span className="italic text-gray-500">{t('Guest')}</span>;
    }

    return <span className="font-medium text-ink">{activity.user_name}</span>;
}

function Section({ title, link, children }) {
    return (
        <section className="rounded-lg bg-white p-4 shadow-sm sm:p-6">
            <div className="mb-3 flex items-baseline justify-between gap-2">
                <h3 className="text-lg font-medium text-ink">{title}</h3>
                {link}
            </div>
            {children}
        </section>
    );
}

export default function Dashboard({ counts, recent_offers: recentOffers, recent_activities: recentActivities }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-ink">{t('Administration')}</h2>}
        >
            <Head title={t('Administration')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        {COUNTS.map(({ key, label }) => (
                            <div key={key} className="rounded-lg bg-white p-4 shadow-sm">
                                <div className="text-3xl font-semibold text-brand-700">{counts[key]}</div>
                                <div className="mt-1 text-sm text-gray-600">{t(label)}</div>
                            </div>
                        ))}
                    </div>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <Section
                            title={t('Latest offers')}
                            link={<Link href={route('offers.index')} className="text-sm text-brand-700 hover:underline">{t('All offers')}</Link>}
                        >
                            {recentOffers.length === 0 ? (
                                <p className="text-sm text-gray-600">{t('No offers yet.')}</p>
                            ) : (
                                <ul className="divide-y divide-gray-100">
                                    {recentOffers.map((offer) => (
                                        <li key={offer.id} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2 text-sm">
                                            <div>
                                                <Link href={route('offers.show', offer.id)} className="font-semibold text-brand-700 hover:underline">
                                                    {offer.number}
                                                </Link>{' '}
                                                <span className="text-ink">{offer.client_name}</span>{' '}
                                                <OfferStatusBadge offer={offer} />
                                                <div className="text-xs text-gray-500">{offer.offer_date}</div>
                                            </div>
                                            <div className="font-semibold text-ink">{formatMoneyOrDash(offer.total_gross_cents)}</div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>

                        <Section
                            title={t('Latest activity')}
                            link={<Link href={route('admin.activity-log')} className="text-sm text-brand-700 hover:underline">{t('View all')}</Link>}
                        >
                            {recentActivities.length === 0 ? (
                                <p className="text-sm text-gray-600">{t('No activity yet.')}</p>
                            ) : (
                                <ul className="divide-y divide-gray-100">
                                    {recentActivities.map((activity) => (
                                        <li key={activity.id} className="py-2 text-sm">
                                            <div className="text-ink">{activityActionLabel(activity.action)}</div>
                                            <div className="text-xs text-gray-500">
                                                {activity.time} · <Actor activity={activity} />
                                                {activity.subject_label ? ` · ${activity.subject_label}` : ''}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    </div>

                    <Section title={t('Shortcuts')}>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {SHORTCUTS.map(({ routeName, title, description }) => (
                                <AdminLinkCard key={routeName} routeName={routeName} title={t(title)} description={t(description)} />
                            ))}
                        </div>
                    </Section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
