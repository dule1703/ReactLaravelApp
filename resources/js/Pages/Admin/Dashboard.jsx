import AdminLinkCard from '@/Components/AdminLinkCard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { Head } from '@inertiajs/react';

export default function Dashboard() {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-ink">
                    {t('Administration')}
                </h2>
            }
        >
            <Head title={t('Administration')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="p-6 text-ink">
                            {t('Admin area. Clients, offers and prices will be managed here.')}
                        </div>
                    </div>

                    <div className="mt-6 grid gap-4 sm:grid-cols-2">
                        <AdminLinkCard
                            routeName="issuer.edit"
                            title={t('Offer issuer')}
                            description={t('Dealer details printed in the header of the offer PDF')}
                        />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
