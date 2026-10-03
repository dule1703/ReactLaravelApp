import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

const TABS = [
    { key: 'models', label: 'Models', route: 'catalog.models.index' },
    { key: 'categories', label: 'Categories', route: 'catalog.categories.index' },
    { key: 'trims', label: 'Trims', route: 'catalog.trims.index' },
    { key: 'engines', label: 'Engines', route: 'catalog.engines.index' },
    { key: 'transmissions', label: 'Transmissions', route: 'catalog.transmissions.index' },
];

// Shared frame of the catalog screens: one navigation item "Catalog", one tab per entity
// (each tab is its own page and URL).
export default function CatalogLayout({ active, children }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-ink">{t('Catalog')}</h2>}
        >
            <Head title={`${t('Catalog')} - ${t(TABS.find((tab) => tab.key === active)?.label ?? 'Catalog')}`} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    <nav aria-label={t('Catalog')} className="flex gap-1 overflow-x-auto border-b border-gray-200 px-4 sm:px-0">
                        {TABS.map((tab) => {
                            const isActive = tab.key === active;

                            return (
                                <Link
                                    key={tab.key}
                                    href={route(tab.route)}
                                    aria-current={isActive ? 'page' : undefined}
                                    className={`whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand-500 ${
                                        isActive
                                            ? 'border-brand text-brand-700'
                                            : 'border-transparent text-gray-600 hover:border-gray-300 hover:text-ink'
                                    }`}
                                >
                                    {t(tab.label)}
                                </Link>
                            );
                        })}
                    </nav>

                    {children}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
