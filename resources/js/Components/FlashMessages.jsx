import { t } from '@/lib/i18n';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const SUCCESS_TIMEOUT_MS = 5000;

function CloseButton({ onClick, className }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={t('Close')}
            className={`ms-4 rounded p-1 focus:outline-none focus:ring-2 ${className}`}
        >
            <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M4.3 4.3a1 1 0 011.4 0L10 8.6l4.3-4.3a1 1 0 111.4 1.4L11.4 10l4.3 4.3a1 1 0 01-1.4 1.4L10 11.4l-4.3 4.3a1 1 0 01-1.4-1.4L8.6 10 4.3 5.7a1 1 0 010-1.4z" />
            </svg>
        </button>
    );
}

/**
 * Shows the one-request flash messages shared by HandleInertiaRequests.
 * Success disappears by itself; an error stays until it is closed, so it is never missed.
 */
export default function FlashMessages() {
    const { flash } = usePage().props;
    const [hidden, setHidden] = useState({ success: false, error: false });

    // Every Inertia visit delivers a new `flash` object, so this also resets the state.
    useEffect(() => {
        setHidden({ success: false, error: false });

        if (!flash?.success) {
            return undefined;
        }

        const timer = setTimeout(
            () => setHidden((current) => ({ ...current, success: true })),
            SUCCESS_TIMEOUT_MS,
        );

        return () => clearTimeout(timer);
    }, [flash]);

    const showSuccess = flash?.success && !hidden.success;
    const showError = flash?.error && !hidden.error;

    if (!showSuccess && !showError) {
        return null;
    }

    return (
        <div className="mx-auto max-w-7xl space-y-2 px-4 pt-4 sm:px-6 lg:px-8">
            {showSuccess && (
                <div
                    role="status"
                    className="flex items-center justify-between rounded-md border border-brand-300 bg-brand-50 px-4 py-3 text-sm font-medium text-brand-800"
                >
                    <span>{flash.success}</span>
                    <CloseButton
                        onClick={() => setHidden({ ...hidden, success: true })}
                        className="text-brand-800 hover:bg-brand-100 focus:ring-brand-500"
                    />
                </div>
            )}

            {showError && (
                <div
                    role="alert"
                    className="flex items-center justify-between rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-danger"
                >
                    <span>{flash.error}</span>
                    <CloseButton
                        onClick={() => setHidden({ ...hidden, error: true })}
                        className="text-danger hover:bg-red-100 focus:ring-red-500"
                    />
                </div>
            )}
        </div>
    );
}
