import ClientProfileForm from '@/Components/ClientProfileForm';
import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import RevealValue from '@/Components/RevealValue';
import SecondaryButton from '@/Components/SecondaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Edit({ client, profile, countries }) {
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const deleteJmbg = () => {
        setDeleting(true);
        router.delete(route('clients.jmbg.destroy', client.id), {
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                setConfirming(false);
            },
        });
    };

    const jmbgExtra = profile.jmbg_masked ? (
        <div className="mt-2 flex flex-wrap items-center gap-4 text-sm">
            <RevealValue clientId={client.id} field="jmbg" masked={profile.jmbg_masked} />
            <button
                type="button"
                onClick={() => setConfirming(true)}
                className="font-medium text-danger hover:underline"
            >
                {t('Delete JMBG')}
            </button>
        </div>
    ) : null;

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-ink">{t('Edit client')}</h2>}
        >
            <Head title={t('Edit client')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <div className="mb-6 flex items-center justify-between gap-4">
                            <p className="text-sm text-gray-600">{client.email}</p>
                            <Link
                                href={route('clients.index')}
                                className="text-sm font-medium text-brand-700 hover:text-brand-800"
                            >
                                {t('Back to clients')}
                            </Link>
                        </div>

                        <ClientProfileForm
                            profile={profile}
                            countries={countries}
                            action={route('clients.update', client.id)}
                            jmbgExtra={jmbgExtra}
                        />
                    </div>
                </div>
            </div>

            <Modal show={confirming} onClose={() => setConfirming(false)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-medium text-ink">{t('Delete JMBG')}</h3>
                    <p className="mt-2 text-sm text-gray-600">
                        {t('Delete the saved JMBG? This cannot be undone.')}
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setConfirming(false)}>{t('Cancel')}</SecondaryButton>
                        <DangerButton onClick={deleteJmbg} disabled={deleting}>
                            {t('Delete')}
                        </DangerButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
