import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { t } from '@/lib/i18n';

export default function Dashboard() {
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
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="p-6 text-ink">
                            {t('You\'re logged in!')}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
