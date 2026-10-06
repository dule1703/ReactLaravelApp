import BusyRegion from '@/Components/BusyRegion';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { activityActionLabel, activityFieldLabel } from '@/lib/activity';
import { t, tOr } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const EMPTY_FILTERS = {
    user_id: '',
    role: '',
    action: '',
    from: '',
    to: '',
    ip: '',
    q: '',
};

const roleLabel = (role) => tOr(`role.${role}`, role);

function Actor({ log }) {
    if (log.actor_type === 'system') {
        return <span className="italic text-gray-500">{t('System')}</span>;
    }

    if (log.actor_type === 'guest') {
        return <span className="italic text-gray-500">{t('Guest')}</span>;
    }

    return (
        <div>
            <div className="font-medium text-ink">{log.user_name}</div>
            <div className="text-xs text-gray-500">{log.user_email}</div>
        </div>
    );
}

function ChangeValue({ value }) {
    if (value === null || value === undefined || value === '') {
        return <span className="text-gray-400">—</span>;
    }

    return <span className="break-all">{String(value)}</span>;
}

function Details({ log, onClose }) {
    const entries = Object.entries(log?.changes ?? {});

    return (
        <Modal show={log !== null} onClose={onClose} maxWidth="xl">
            {log && (
                <div className="p-6">
                    <h3 className="text-lg font-medium text-ink">{activityActionLabel(log.action)}</h3>
                    <p className="mt-1 text-sm text-gray-600">
                        {log.time} · {log.subject_label}
                    </p>
                    {log.description && (
                        <p className="mt-2 text-sm text-ink">{log.description}</p>
                    )}

                    <h4 className="mt-6 text-sm font-semibold text-ink">{t('Changes')}</h4>
                    {entries.length === 0 ? (
                        <p className="mt-2 text-sm text-gray-500">{t('No recorded changes.')}</p>
                    ) : (
                        <table className="mt-2 w-full text-left text-sm">
                            <thead className="text-xs uppercase text-gray-500">
                                <tr>
                                    <th className="py-1 pe-3">{t('Field')}</th>
                                    <th className="py-1 pe-3">{t('Old value')}</th>
                                    <th className="py-1">{t('New value')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {entries.map(([field, change]) => (
                                    <tr key={field}>
                                        <td className="py-1.5 pe-3 font-medium">{activityFieldLabel(log.action, field)}</td>
                                        {change.redacted ? (
                                            <td colSpan={2} className="py-1.5 italic text-gray-500">
                                                {t('Value not stored')}
                                            </td>
                                        ) : (
                                            <>
                                                <td className="py-1.5 pe-3">
                                                    <ChangeValue value={change.old} />
                                                </td>
                                                <td className="py-1.5">
                                                    <ChangeValue value={change.new} />
                                                </td>
                                            </>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={onClose}>{t('Close')}</SecondaryButton>
                    </div>
                </div>
            )}
        </Modal>
    );
}

export default function ActivityLog({ logs, filters, users, actions }) {
    const [form, setForm] = useState({ ...EMPTY_FILTERS, ...filters });
    const [selected, setSelected] = useState(null);

    const setField = (name) => (e) => setForm({ ...form, [name]: e.target.value });

    const apply = (values) => {
        const params = Object.fromEntries(Object.entries(values).filter(([, v]) => v !== ''));
        router.get(route('admin.activity-log'), params, { preserveState: true, replace: true });
    };

    const submit = (e) => {
        e.preventDefault();
        apply(form);
    };

    const reset = () => {
        setForm(EMPTY_FILTERS);
        apply(EMPTY_FILTERS);
    };

    const selectClass = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500';

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-ink">
                    {t('Activity log')}
                </h2>
            }
        >
            <Head title={t('Activity log')} />

            <div className="py-8">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    <form
                        onSubmit={submit}
                        className="grid grid-cols-1 gap-4 bg-white p-4 shadow-sm sm:rounded-lg md:grid-cols-4"
                    >
                        <div className="md:col-span-2">
                            <InputLabel htmlFor="q" value={t('Search')} />
                            <TextInput
                                id="q"
                                className="mt-1 block w-full"
                                value={form.q}
                                onChange={setField('q')}
                                placeholder={t('Name, email, subject or description')}
                            />
                        </div>

                        <div>
                            <InputLabel htmlFor="user_id" value={t('User')} />
                            <select id="user_id" className={selectClass} value={form.user_id} onChange={setField('user_id')}>
                                <option value="">{t('All users')}</option>
                                {users.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name} ({u.email})
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <InputLabel htmlFor="role" value={t('Role')} />
                            <select id="role" className={selectClass} value={form.role} onChange={setField('role')}>
                                <option value="">{t('All roles')}</option>
                                <option value="admin">{roleLabel('admin')}</option>
                                <option value="client">{roleLabel('client')}</option>
                            </select>
                        </div>

                        <div>
                            <InputLabel htmlFor="action" value={t('Action')} />
                            <select id="action" className={selectClass} value={form.action} onChange={setField('action')}>
                                <option value="">{t('All actions')}</option>
                                {actions.map((a) => (
                                    <option key={a} value={a}>
                                        {activityActionLabel(a)}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <InputLabel htmlFor="from" value={t('From')} />
                            <TextInput id="from" type="date" className="mt-1 block w-full" value={form.from} onChange={setField('from')} />
                        </div>

                        <div>
                            <InputLabel htmlFor="to" value={t('To')} />
                            <TextInput id="to" type="date" className="mt-1 block w-full" value={form.to} onChange={setField('to')} />
                        </div>

                        <div>
                            <InputLabel htmlFor="ip" value={t('IP address')} />
                            <TextInput id="ip" className="mt-1 block w-full" value={form.ip} onChange={setField('ip')} />
                        </div>

                        <div className="flex flex-wrap items-end gap-2 md:col-span-4">
                            <PrimaryButton type="submit">{t('Apply')}</PrimaryButton>
                            <SecondaryButton type="button" onClick={reset}>
                                {t('Reset')}
                            </SecondaryButton>
                        </div>
                    </form>

                    <BusyRegion className="space-y-4">
                    <div className="overflow-x-auto bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                            <thead className="bg-surface text-xs uppercase text-gray-500">
                                <tr>
                                    <th className="px-4 py-3">{t('Time')}</th>
                                    <th className="px-4 py-3">{t('User')}</th>
                                    <th className="px-4 py-3">{t('Role')}</th>
                                    <th className="px-4 py-3">{t('Action')}</th>
                                    <th className="px-4 py-3">{t('Subject')}</th>
                                    <th className="px-4 py-3">{t('IP address')}</th>
                                    <th className="px-4 py-3">{t('Device')}</th>
                                    <th className="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {logs.data.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-8 text-center text-gray-500">
                                            {t('No activity found.')}
                                        </td>
                                    </tr>
                                )}
                                {logs.data.map((log) => (
                                    <tr key={log.id}>
                                        <td className="whitespace-nowrap px-4 py-3">{log.time}</td>
                                        <td className="px-4 py-3">
                                            <Actor log={log} />
                                        </td>
                                        <td className="px-4 py-3">{log.user_role ? roleLabel(log.user_role) : '—'}</td>
                                        <td className="px-4 py-3">{activityActionLabel(log.action)}</td>
                                        <td className="px-4 py-3">{log.subject_label ?? '—'}</td>
                                        <td className="whitespace-nowrap px-4 py-3">{log.ip ?? '—'}</td>
                                        <td className="whitespace-nowrap px-4 py-3">{log.device ?? '—'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <button
                                                type="button"
                                                onClick={() => setSelected(log)}
                                                className="rounded-md text-sm font-medium text-brand-700 underline hover:text-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-500"
                                            >
                                                {t('Details')}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination paginator={logs} />
                    </BusyRegion>
                </div>
            </div>

            <Details log={selected} onClose={() => setSelected(null)} />
        </AuthenticatedLayout>
    );
}
