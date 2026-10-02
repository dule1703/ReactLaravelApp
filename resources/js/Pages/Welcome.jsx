import ApplicationLogo from '@/Components/ApplicationLogo';
import { t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

export default function Welcome({ auth, canLogin, canRegister }) {
    const linkClass =
        'rounded-md px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2';

    return (
        <>
            <Head title={t('Home')} />

            <div className="flex min-h-screen flex-col bg-surface">
                <header className="bg-brand-dark">
                    <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                        <Link href="/" className="flex items-center gap-3">
                            <ApplicationLogo className="h-10 w-auto rounded bg-white p-1" />
                            <span className="font-semibold text-white">
                                {t('Škoda Configurator')}
                            </span>
                        </Link>

                        <nav className="flex items-center gap-2">
                            {auth.user ? (
                                <Link
                                    href={route('dashboard')}
                                    className={`${linkClass} bg-white text-brand-dark hover:bg-brand-100`}
                                >
                                    {t('Dashboard')}
                                </Link>
                            ) : (
                                <>
                                    {canLogin && (
                                        <Link
                                            href={route('login')}
                                            className={`${linkClass} text-white hover:bg-white/10`}
                                        >
                                            {t('Log in')}
                                        </Link>
                                    )}
                                    {canRegister && (
                                        <Link
                                            href={route('register')}
                                            className={`${linkClass} bg-brand-500 text-white hover:bg-brand-600`}
                                        >
                                            {t('Register')}
                                        </Link>
                                    )}
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <main className="flex flex-1 items-center">
                    <div className="mx-auto max-w-3xl px-4 py-16 text-center sm:px-6">
                        <h1 className="text-4xl font-bold tracking-tight text-brand-dark sm:text-5xl">
                            {t('Build your Škoda offer online')}
                        </h1>
                        <p className="mt-6 text-lg text-gray-600">
                            {t(
                                'Choose a model, package and engine, add equipment and get a clear offer with prices and VAT.',
                            )}
                        </p>
                    </div>
                </main>
            </div>
        </>
    );
}
