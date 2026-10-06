import ClientProfileForm from '@/Components/ClientProfileForm';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

// The salon flow: the admin makes the account and the profile; the client gets an email with a link
// to set their own password (nobody knows a password, none is entered or shown here).
const EMPTY_PROFILE = { type: 'individual', full_name: '', pib: '', address: '', postal_code: '', city: '', country: 'RS' };

export default function Create({ countries, linkMinutes }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-ink">{t('New client')}</h2>}>
            <Head title={t('New client')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <div className="mb-6 flex items-center justify-between gap-4">
                            <p className="max-w-xl text-sm text-gray-600">
                                {t('The client receives an email with a link to set their password (valid :minutes minutes). No password is entered here.', { minutes: linkMinutes })}
                            </p>
                            <Link href={route('clients.index')} className="text-sm font-medium text-brand-700 hover:text-brand-800">
                                {t('Back to clients')}
                            </Link>
                        </div>

                        <ClientProfileForm
                            profile={EMPTY_PROFILE}
                            countries={countries}
                            action={route('clients.store')}
                            jmbgOptional
                            create
                        />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
