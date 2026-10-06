import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { t } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Status buttons on the page of an offer (4.6b). The client withdraws their own offer; the admin
 * undoes a withdrawal (no modal, the answer is a flash message) and deletes the offer. Withdrawing
 * and deleting ask first in a modal. The server decides through the Policy; the buttons only hide
 * what the role may not do. `processing` blocks a double click.
 */
export default function OfferStatusActions({ offer, isAdmin }) {
    const [confirming, setConfirming] = useState(null); // 'withdraw' | 'delete' | null
    const [processing, setProcessing] = useState(false);

    const request = (method, name) => {
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirming(null);
            },
        };

        // router.delete(url, options) has no data argument (router.post(url, data, options) does):
        // options passed as the second argument of delete would be ignored.
        return method === 'delete'
            ? router.delete(route(name, offer.id), options)
            : router.post(route(name, offer.id), {}, options);
    };

    const modal = {
        withdraw: {
            title: t('Withdraw the offer?'),
            text: t('The offer stays visible and printable, marked as withdrawn, and can no longer be changed. Only an administrator can undo the withdrawal.'),
            label: t('Withdraw offer'),
            run: () => request('post', 'offers.withdraw'),
        },
        delete: {
            title: t('Delete the offer?'),
            text: t('The offer disappears from the lists and can no longer be opened. Its number is not used again. You can restore it from the list of deleted offers.'),
            label: t('Delete offer'),
            run: () => request('delete', 'offers.destroy'),
        },
    }[confirming];

    return (
        <>
            {isAdmin && offer.withdrawn_at && (
                <SecondaryButton disabled={processing} onClick={() => request('post', 'offers.withdrawal.revert')}>{t('Undo withdrawal')}</SecondaryButton>
            )}
            {!isAdmin && !offer.withdrawn_at && <SecondaryButton onClick={() => setConfirming('withdraw')}>{t('Withdraw offer')}</SecondaryButton>}
            {isAdmin && <SecondaryButton onClick={() => setConfirming('delete')}>{t('Delete offer')}</SecondaryButton>}

            <Modal show={confirming !== null} maxWidth="md" onClose={() => !processing && setConfirming(null)}>
                {modal && (
                    <div className="space-y-4 p-6">
                        <h3 className="text-lg font-semibold text-ink">{modal.title}</h3>
                        <p className="text-sm text-gray-600">{modal.text}</p>
                        <div className="flex justify-end gap-3">
                            <SecondaryButton disabled={processing} onClick={() => setConfirming(null)}>{t('Cancel')}</SecondaryButton>
                            <DangerButton disabled={processing} onClick={modal.run}>{modal.label}</DangerButton>
                        </div>
                    </div>
                )}
            </Modal>
        </>
    );
}
