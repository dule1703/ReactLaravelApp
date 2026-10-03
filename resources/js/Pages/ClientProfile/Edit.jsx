import ClientProfileForm from '@/Components/ClientProfileForm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { Head } from '@inertiajs/react';

export default function Edit({ profile, countries }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-ink">
                    {t('My client profile')}
                </h2>
            }
        >
            <Head title={t('My profile')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <p className="mb-6 text-sm text-gray-600">
                            {t('Identification and address used on your offers.')}
                        </p>

                        <ClientProfileForm
                            profile={profile}
                            countries={countries}
                            action={route('client-profile.update')}
                        />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
