import ApplicationLogo from '@/Components/ApplicationLogo';
import FlashMessages from '@/Components/FlashMessages';
import { t } from '@/lib/i18n';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

const SOON_BADGE =
    'ms-2 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500';

// Disabled item: not a real link (no href), not focusable, announced as disabled.
function SoonItem({ item, mobile }) {
    return (
        <span
            role="link"
            aria-disabled="true"
            className={
                mobile
                    ? 'flex w-full cursor-not-allowed items-center border-l-4 border-transparent py-2 pe-4 ps-3 text-base font-medium text-gray-400'
                    : 'inline-flex cursor-not-allowed items-center border-b-2 border-transparent px-1 pt-1 text-sm font-medium text-gray-400'
            }
        >
            {item.label}
            <span className={SOON_BADGE}>{t('Soon')}</span>
        </span>
    );
}

function NavItem({ item, mobile = false }) {
    if (item.soon) {
        return <SoonItem item={item} mobile={mobile} />;
    }

    const active = route().current(item.pattern);

    if (mobile) {
        return (
            <Link
                href={item.href}
                aria-current={active ? 'page' : undefined}
                className={`flex w-full items-start border-l-4 py-2 pe-4 ps-3 text-base font-medium transition duration-150 ease-in-out focus:outline-none ${
                    active
                        ? 'border-brand-500 bg-brand-50 text-brand-800'
                        : 'border-transparent text-gray-600 hover:border-gray-300 hover:bg-gray-50 hover:text-ink focus:border-gray-300 focus:bg-gray-50'
                }`}
            >
                {item.label}
            </Link>
        );
    }

    return (
        <Link
            href={item.href}
            aria-current={active ? 'page' : undefined}
            className={`inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none ${
                active
                    ? 'border-brand-500 text-ink'
                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 focus:border-gray-300 focus:text-gray-700'
            }`}
        >
            {item.label}
        </Link>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const { auth, nav } = usePage().props;
    const user = auth.user;

    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <div className="min-h-screen bg-surface">
            <nav className="border-b border-gray-100 bg-white" aria-label={t('Main navigation')}>
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex min-h-16 items-center justify-between gap-3 py-2">
                        <div className="flex items-center">
                            <Link href="/" className="flex shrink-0 items-center">
                                <ApplicationLogo className="block" />
                            </Link>

                            <div className="hidden space-x-4 xl:space-x-6 lg:ms-10 lg:flex">
                                {nav.map((item) => (
                                    <NavItem key={item.key} item={item} />
                                ))}
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <Link
                                href={route('profile.edit')}
                                className="hidden max-w-[8rem] truncate xl:max-w-[12rem] rounded-md px-2 py-1 text-sm font-medium text-gray-600 hover:text-ink focus:outline-none focus:ring-2 focus:ring-brand-500 lg:block"
                                title={user.email}
                            >
                                {user.name}
                            </Link>

                            {/* Logout is always visible: text on desktop, icon next to the burger on mobile. */}
                            <Link
                                href={route('logout')}
                                method="post"
                                as="button"
                                className="hidden items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-ink hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500 lg:inline-flex"
                            >
                                {t('Log Out')}
                            </Link>
                            <Link
                                href={route('logout')}
                                method="post"
                                as="button"
                                aria-label={t('Log Out')}
                                title={t('Log Out')}
                                className="inline-flex items-center justify-center rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-ink focus:outline-none focus:ring-2 focus:ring-brand-500 lg:hidden"
                            >
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                                </svg>
                            </Link>

                            <button
                                type="button"
                                onClick={() => setMenuOpen((open) => !open)}
                                aria-label={t('Menu')}
                                aria-expanded={menuOpen}
                                className="inline-flex items-center justify-center rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-ink focus:outline-none focus:ring-2 focus:ring-brand-500 lg:hidden"
                            >
                                <svg className="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <path
                                        className={menuOpen ? 'hidden' : 'inline-flex'}
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={menuOpen ? 'inline-flex' : 'hidden'}
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div className={`${menuOpen ? 'block' : 'hidden'} lg:hidden`}>
                    <div className="space-y-1 pb-3 pt-2">
                        {nav.map((item) => (
                            <NavItem key={item.key} item={item} mobile />
                        ))}
                    </div>

                    <div className="border-t border-gray-200 pb-3 pt-4">
                        <Link href={route('profile.edit')} className="block px-4 focus:outline-none">
                            <div className="text-base font-medium text-ink">{user.name}</div>
                            <div className="text-sm font-medium text-gray-500">{user.email}</div>
                        </Link>
                    </div>
                </div>
            </nav>

            {header && (
                <header className="bg-white shadow">
                    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">{header}</div>
                </header>
            )}

            <FlashMessages />

            <main>{children}</main>
        </div>
    );
}
