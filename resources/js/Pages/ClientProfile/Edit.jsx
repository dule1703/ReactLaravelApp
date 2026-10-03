import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';

function Field({ id, label, optional = false, error, hint, children }) {
    return (
        <div>
            <InputLabel htmlFor={id}>
                {label}
                {optional && <span className="font-normal text-gray-500"> ({t('Optional')})</span>}
            </InputLabel>
            {children}
            {hint && (
                <p id={`${id}-hint`} className="mt-1 text-sm text-gray-600">
                    {hint}
                </p>
            )}
            <InputError className="mt-2" message={error} />
        </div>
    );
}

export default function Edit({ profile, countries }) {
    const { data, setData, patch, errors, processing, reset } = useForm({
        type: profile.type,
        full_name: profile.full_name ?? '',
        // Never prefilled: the full JMBG is not sent to the browser. Empty means "keep".
        jmbg: '',
        pib: profile.pib ?? '',
        address: profile.address ?? '',
        postal_code: profile.postal_code ?? '',
        city: profile.city ?? '',
        country: profile.country,
    });

    const isCompany = data.type === 'company';

    const submit = (e) => {
        e.preventDefault();

        patch(route('client-profile.update'), { onSuccess: () => reset('jmbg') });
    };

    const input = (id, extra = {}) => ({
        id,
        className: 'mt-1 block w-full',
        value: data[id],
        onChange: (e) => setData(id, e.target.value),
        ...extra,
    });

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-ink">
                    {t('My client profile')}
                </h2>
            }
        >
            <Head title={t('My profile')} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <header>
                            <p className="text-sm text-gray-600">
                                {t('Identification and address used on your offers.')}
                            </p>
                        </header>

                        <form onSubmit={submit} className="mt-6 max-w-xl space-y-6">
                            <Field id="type" label={t('Client type')} error={errors.type}>
                                <SelectInput {...input('type')}>
                                    <option value="individual">{t('Individual (natural person)')}</option>
                                    <option value="company">{t('Company (legal entity)')}</option>
                                </SelectInput>
                            </Field>

                            <Field
                                id="full_name"
                                label={isCompany ? t('Company name') : t('Full name')}
                                error={errors.full_name}
                            >
                                <TextInput {...input('full_name', { required: true, autoComplete: 'name' })} />
                            </Field>

                            <Field
                                id="jmbg"
                                label="JMBG"
                                optional={isCompany}
                                error={errors.jmbg}
                                hint={
                                    profile.jmbg_masked
                                        ? t('Saved JMBG: :jmbg. Leave empty to keep it.', { jmbg: profile.jmbg_masked })
                                        : t('No JMBG saved yet.')
                                }
                            >
                                <TextInput
                                    {...input('jmbg', {
                                        inputMode: 'numeric',
                                        autoComplete: 'off',
                                        'aria-describedby': 'jmbg-hint',
                                    })}
                                />
                            </Field>

                            <Field id="pib" label="PIB" optional={!isCompany} error={errors.pib}>
                                <TextInput {...input('pib', { inputMode: 'numeric', maxLength: 9 })} />
                            </Field>

                            <Field id="address" label={t('Address')} error={errors.address}>
                                <TextInput {...input('address', { required: true, autoComplete: 'street-address' })} />
                            </Field>

                            <div className="grid gap-6 sm:grid-cols-2">
                                <Field id="postal_code" label={t('Postal code')} error={errors.postal_code}>
                                    <TextInput
                                        {...input('postal_code', {
                                            required: true,
                                            inputMode: 'numeric',
                                            maxLength: 5,
                                            autoComplete: 'postal-code',
                                        })}
                                    />
                                </Field>

                                <Field id="city" label={t('City')} error={errors.city}>
                                    <TextInput {...input('city', { required: true, autoComplete: 'address-level2' })} />
                                </Field>
                            </div>

                            <Field id="country" label={t('Country')} error={errors.country}>
                                <SelectInput {...input('country')}>
                                    {countries.map((country) => (
                                        <option key={country.code} value={country.code}>
                                            {country.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </Field>

                            <PrimaryButton disabled={processing}>{t('Save')}</PrimaryButton>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
