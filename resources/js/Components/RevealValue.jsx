import { t } from '@/lib/i18n';
import { useEffect, useState } from 'react';

const HIDE_AFTER_MS = 15000;

// Shows a masked value with a "Show" button. The full value is fetched on demand from the
// logged reveal endpoint, kept only in component state and hidden again after 15 seconds.
export default function RevealValue({ clientId, field, masked }) {
    const [value, setValue] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (value === null) {
            return undefined;
        }

        const timer = setTimeout(() => setValue(null), HIDE_AFTER_MS);

        return () => clearTimeout(timer);
    }, [value]);

    if (!masked) {
        return <span className="text-gray-400">—</span>;
    }

    const reveal = async () => {
        setLoading(true);
        setError(null);

        try {
            const response = await window.axios.post(route('clients.reveal', clientId), { field });
            setValue(response.data.value ?? '');
        } catch (e) {
            setError(
                e.response?.status === 429
                    ? t('Too many requests. Try again shortly.')
                    : t('Could not load the value.'),
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <span className="font-mono">{value ?? masked}</span>
            <button
                type="button"
                onClick={value === null ? reveal : () => setValue(null)}
                disabled={loading}
                className="rounded text-xs font-medium text-brand-700 underline hover:text-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:opacity-50"
            >
                {value === null ? t('Show') : t('Hide')}
            </button>
            {error && (
                <span role="alert" className="text-xs text-danger">
                    {error}
                </span>
            )}
        </span>
    );
}
