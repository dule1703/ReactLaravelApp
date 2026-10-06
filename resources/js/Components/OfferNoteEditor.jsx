import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { t } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The note of an offer, editable in place (4.6c). The note is the only thing of an offer that can be
 * changed. `canEdit` and `noteMax` come from the server (the Policy decides; the button only hides
 * what is not allowed, e.g. on a withdrawn offer).
 */
export default function OfferNoteEditor({ offer, canEdit, noteMax }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ note: offer.note ?? '' });

    const open = () => {
        form.setData('note', offer.note ?? '');
        form.clearErrors();
        setEditing(true);
    };

    const cancel = () => {
        form.clearErrors();
        setEditing(false);
    };

    const submit = (event) => {
        event.preventDefault();
        form.patch(route('offers.note.update', offer.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    return (
        <div className="mt-3">
            <div className="flex items-center justify-between gap-3">
                <dt className="text-xs uppercase text-gray-500">{t('Note')}</dt>
                {canEdit && !editing && (
                    <button type="button" onClick={open} className="text-sm font-medium text-brand-700 hover:text-brand-800">
                        {t('Edit note')}
                    </button>
                )}
            </div>

            {editing ? (
                <form onSubmit={submit} className="mt-1 space-y-2">
                    <textarea
                        aria-label={t('Note')}
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                        maxLength={noteMax}
                        rows={4}
                        className="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
                    />
                    <div className="flex items-center justify-between text-xs text-gray-500">
                        <span>{t(':count of :max characters', { count: form.data.note.length, max: noteMax })}</span>
                    </div>
                    <InputError message={form.errors.note} />
                    <div className="flex gap-3">
                        <PrimaryButton type="submit" disabled={form.processing}>{t('Save')}</PrimaryButton>
                        <SecondaryButton type="button" disabled={form.processing} onClick={cancel}>{t('Cancel')}</SecondaryButton>
                    </div>
                </form>
            ) : (
                <dd className="mt-0.5 whitespace-pre-line text-sm text-ink">{offer.note || '-'}</dd>
            )}
        </div>
    );
}
