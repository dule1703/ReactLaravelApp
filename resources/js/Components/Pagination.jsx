import { t } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

// Works with a Laravel paginator serialised by Inertia (prev/next + summary).
export default function Pagination({ paginator }) {
    if (!paginator || paginator.total === 0) {
        return null;
    }

    const linkClass =
        'rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-ink hover:bg-gray-50';
    const disabledClass =
        'rounded-md border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm text-gray-400';

    return (
        <div className="flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p className="text-sm text-gray-600">
                {t('Showing :from-:to of :total', {
                    from: paginator.from,
                    to: paginator.to,
                    total: paginator.total,
                })}
            </p>

            <div className="flex flex-wrap gap-2">
                {paginator.prev_page_url ? (
                    <Link href={paginator.prev_page_url} className={linkClass} preserveScroll>
                        {t('Previous')}
                    </Link>
                ) : (
                    <span className={disabledClass}>{t('Previous')}</span>
                )}

                {paginator.next_page_url ? (
                    <Link href={paginator.next_page_url} className={linkClass} preserveScroll>
                        {t('Next')}
                    </Link>
                ) : (
                    <span className={disabledClass}>{t('Next')}</span>
                )}
            </div>
        </div>
    );
}
