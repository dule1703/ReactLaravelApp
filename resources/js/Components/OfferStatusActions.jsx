import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { t } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Status buttons on the page of an offer (4.6b). The client withdraws their own offer (a modal asks
 * first); the admin undoes a withdrawal (no modal, the answer is a flash message). The server decides
 * through the Policy; the buttons only hide what the role may not do. `processing` blocks a double click.
 */
export default function OfferStatusActions({ offer, isAdmin }) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const send = (name) =>
        router.post(route(name, offer.id), {}, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirming(false);
            },
        });

    if (isAdmin) {
        return offer.withdrawn_at ? (
            <SecondaryButton disabled={processing} onClick={() => send('offers.withdrawal.revert')}>{t('Undo withdrawal')}</SecondaryButton>
        ) : null;
    }

    if (offer.withdrawn_at) {
        return null;
    }

    return (
        <>
            <SecondaryButton onClick={() => setConfirming(true)}>{t('Withdraw offer')}</SecondaryButton>
            <Modal show={confirming} maxWidth="md" onClose={() => !processing && setConfirming(false)}>
                <div className="space-y-4 p-6">
                    <h3 className="text-lg font-semibold text-ink">{t('Withdraw the offer?')}</h3>
                    <p className="text-sm text-gray-600">
                        {t('The offer stays visible and printable, marked as withdrawn, and can no longer be changed. Only an administrator can undo the withdrawal.')}
                    </p>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton disabled={processing} onClick={() => setConfirming(false)}>{t('Cancel')}</SecondaryButton>
                        <DangerButton disabled={processing} onClick={() => send('offers.withdraw')}>{t('Withdraw offer')}</DangerButton>
                    </div>
                </div>
            </Modal>
        </>
    );
}
