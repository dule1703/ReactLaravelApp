import { categoryLabel } from '@/Components/Configurator/EquipmentChoices';
import OfferPdfActions from '@/Components/OfferPdfActions';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { groupBy } from '@/lib/configurator';
import { t, tOr } from '@/lib/i18n';
import { formatMoneyOrDash, formatRateBp } from '@/lib/money';
import { Head, Link } from '@inertiajs/react';

// Old offers keep working when the catalog gets a new fuel type or drive: the raw value is the fallback.
const fuelLabel = (value) => tOr(`fuel.${value}`, value);
const driveLabel = (value) => tOr(`drive.${value}`, value);

function Field({ label, children }) {
    return (
        <div>
            <dt className="text-xs uppercase text-gray-500">{label}</dt>
            <dd className="mt-0.5 text-sm text-ink">{children || '-'}</dd>
        </div>
    );
}

function ItemCard({ item, index }) {
    const groups = groupBy(item.options, (option) => option.category);

    return (
        <li className="rounded-lg border border-gray-200 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h4 className="font-semibold text-ink">
                        {index + 1}. {item.quantity} × {item.car_model_name} {item.trim_name}
                    </h4>
                    <p className="mt-1 text-sm text-gray-600">
                        {item.engine_name} · {item.power_kw} kW · {fuelLabel(item.fuel_type)} · {item.transmission_name} · {driveLabel(item.drive)}
                    </p>
                </div>
                <dl className="text-right text-sm">
                    <dt className="text-xs uppercase text-gray-500">{t('Price of one vehicle')} ({t('without VAT')})</dt>
                    <dd className="font-medium text-ink">{formatMoneyOrDash(item.version_price_cents)}</dd>
                </dl>
            </div>

            {item.options.length > 0 && (
                <div className="mt-3 space-y-3">
                    {groups.map(([category, options]) => (
                        <div key={category}>
                            <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500">{categoryLabel(category)}</h5>
                            <ul className="mt-1 divide-y divide-gray-100 text-sm">
                                {options.map((option) => (
                                    <li key={option.id} className="flex items-center justify-between gap-3 py-1">
                                        <span>
                                            {option.name}
                                            {option.group_name && <span className="text-gray-500"> · {option.group_name}</span>}
                                            {option.is_surcharge && (
                                                <span className="ms-2 rounded bg-surface px-1.5 py-0.5 text-xs font-medium text-gray-700">{t('surcharge')}</span>
                                            )}
                                        </span>
                                        <span className="whitespace-nowrap text-gray-700">{formatMoneyOrDash(option.price_cents)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}

            <p className="mt-3 flex justify-between border-t border-gray-200 pt-2 text-sm">
                <span className="text-gray-600">{t('Line without VAT')}</span>
                <span className="font-semibold text-ink">{formatMoneyOrDash(item.line_net_cents)}</span>
            </p>
        </li>
    );
}

export default function Show({ offer }) {
    const { client } = offer;

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-xl font-semibold leading-tight text-ink">{t('Offer :number', { number: offer.number })}</h2>
                    <div className="flex flex-wrap items-center gap-3">
                        <OfferPdfActions offer={offer} />
                        <Link href={route('offers.index')} className="text-sm font-medium text-brand-700 hover:text-brand-800">{t('Back to offers')}</Link>
                    </div>
                </div>
            }
        >
            <Head title={t('Offer :number', { number: offer.number })} />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <section className="grid gap-6 rounded-lg bg-white p-4 shadow sm:grid-cols-2 sm:p-6">
                        <div>
                            <h3 className="mb-3 text-base font-semibold text-ink">{t('Offer')}</h3>
                            <dl className="grid grid-cols-2 gap-3">
                                <Field label={t('Offer number')}>{offer.number}</Field>
                                <Field label={t('Date')}>{offer.offer_date}</Field>
                                <Field label={t('VAT (%)')}>{formatRateBp(offer.vat_rate_bp)}</Field>
                            </dl>
                            <div className="mt-3">
                                <dt className="text-xs uppercase text-gray-500">{t('Note')}</dt>
                                <dd className="mt-0.5 whitespace-pre-line text-sm text-ink">{offer.note || '-'}</dd>
                            </div>
                        </div>

                        <div>
                            <h3 className="mb-3 flex items-center justify-between text-base font-semibold text-ink">
                                {t('Client')}
                                {offer.client_profile_id != null && (
                                    <Link href={route('clients.edit', offer.client_profile_id)} className="text-sm font-medium text-brand-700 hover:text-brand-800">{t('Open client')}</Link>
                                )}
                            </h3>
                            <dl className="grid grid-cols-2 gap-3">
                                <Field label={t('Full name or company name')}>{client.name}</Field>
                                <Field label={t('Type')}>{client.type === 'company' ? t('Company (legal entity)') : t('Individual (natural person)')}</Field>
                                <Field label="PIB">{client.pib}</Field>
                                <Field label={t('Address')}>{client.address}</Field>
                                <Field label={t('Postal code')}>{client.postal_code}</Field>
                                <Field label={t('City')}>{client.city}</Field>
                                <Field label={t('Country')}>{client.country}</Field>
                            </dl>
                            <p className="mt-3 text-xs text-gray-500">{t('Client details as they were when the offer was made.')}</p>
                        </div>
                    </section>

                    <section className="rounded-lg bg-white p-4 shadow sm:p-6">
                        <h3 className="mb-4 text-base font-semibold text-ink">{t('Items')}</h3>
                        {offer.items.length === 0 ? (
                            <p className="text-sm text-gray-500">{t('This offer has no items.')}</p>
                        ) : (
                            <ol className="space-y-4">
                                {offer.items.map((item, index) => <ItemCard key={item.id} item={item} index={index} />)}
                            </ol>
                        )}
                    </section>

                    <section className="rounded-lg bg-white p-4 shadow sm:p-6">
                        <dl className="ms-auto max-w-sm space-y-1 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-gray-600">{t('Total without VAT')}</dt>
                                <dd className="font-medium text-ink">{formatMoneyOrDash(offer.total_net_cents)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt className="text-gray-600">{t('VAT :rate%', { rate: formatRateBp(offer.vat_rate_bp) })}</dt>
                                <dd className="font-medium text-ink">{formatMoneyOrDash(offer.vat_cents)}</dd>
                            </div>
                            <div className="flex justify-between border-t border-gray-200 pt-2 text-base">
                                <dt className="font-semibold text-ink">{t('TOTAL OFFER')}</dt>
                                <dd className="font-bold text-brand-700">{formatMoneyOrDash(offer.total_gross_cents)}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
