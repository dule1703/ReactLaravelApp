import ApplicationLogo from '@/Components/ApplicationLogo';
import FlashMessages from '@/Components/FlashMessages';
import { t } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-surface pt-6 sm:justify-center sm:pt-0">
            <Link href="/" className="flex flex-col items-center gap-3">
                <ApplicationLogo className="h-20 w-auto" />
                <span className="text-lg font-semibold tracking-tight text-brand-dark">
                    {t('Škoda Configurator')}
                </span>
            </Link>

            <div className="mt-4 w-full sm:max-w-md">
                <FlashMessages />
            </div>

            <div className="mt-6 w-full overflow-hidden border-t-4 border-brand-500 bg-white px-6 py-6 shadow-md sm:max-w-md sm:rounded-lg">
                {children}
            </div>
        </div>
    );
}
