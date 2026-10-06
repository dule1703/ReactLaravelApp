import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import { t } from '@/lib/i18n';
import { Link, useForm } from '@inertiajs/react';

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

// Shared by the client's own profile page and the admin edit page. The full JMBG is never
// prefilled (only the mask is shown as text); an empty JMBG means "keep the stored one".
// `jmbgExtra` is an optional slot under the JMBG field (admin: reveal / delete actions).
// `jmbgOptional`: the JMBG may stay empty (the admin; a client made in the salon has none).
// `create`: the admin makes a new client (POST, an email field, no stored values; a duplicate comes
// back as an error with the id of the existing client, and a duplicate PIB asks for a confirmation).
export default function ClientProfileForm({ profile, countries, action, jmbgExtra = null, jmbgOptional = false, create = false }) {
    const { data, setData, patch, post, errors, processing, reset } = useForm({
        type: profile.type,
        full_name: profile.full_name ?? '',
        ...(create ? { email: '', confirm_duplicate_pib: false } : {}),
        jmbg: '',
        pib: profile.pib ?? '',
        address: profile.address ?? '',
        postal_code: profile.postal_code ?? '',
        city: profile.city ?? '',
        country: profile.country,
    });

    const isCompany = data.type === 'company';
    const pibNeedsConfirmation = create && Boolean(errors['existing_client.pib']);

    const submit = (e) => {
        e.preventDefault();

        if (create) {
            post(action, { onSuccess: () => reset('jmbg') });

            return;
        }

        patch(action, { onSuccess: () => reset('jmbg') });
    };

    // The admin gets a link to the client that already exists.
    const existing = (field) =>
        errors[`existing_client.${field}`] ? (
            <p className="mt-1 text-sm">
                <Link href={route('clients.edit', errors[`existing_client.${field}`])} className="font-medium text-brand-700 underline">
                    {t('Open the existing client')}
                </Link>
            </p>
        ) : null;

    const input = (id, extra = {}) => ({
        id,
        className: 'mt-1 block w-full',
        value: data[id],
        onChange: (e) => setData(id, e.target.value),
        ...extra,
    });

    return (
        <form onSubmit={submit} className="max-w-xl space-y-6">
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

            {create && (
                <Field id="email" label={t('Email')} error={errors.email} hint={t('The client gets a link to set the password at this address.')}>
                    <TextInput {...input('email', { type: 'email', required: true, autoComplete: 'off', 'aria-describedby': 'email-hint' })} />
                    {existing('email')}
                </Field>
            )}

            <Field
                id="jmbg"
                label="JMBG"
                optional={isCompany || jmbgOptional}
                error={errors.jmbg}
                hint={
                    create
                        ? t('Optional: a client can be created without a JMBG.')
                        : profile.jmbg_masked
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
                {jmbgExtra}
                {existing('jmbg')}
            </Field>

            <Field id="pib" label="PIB" optional={!isCompany} error={errors.pib}>
                <TextInput {...input('pib', { inputMode: 'numeric', maxLength: 9 })} />
                {existing('pib')}
                {pibNeedsConfirmation && (
                    <label className="mt-2 flex items-center gap-2 text-sm text-ink">
                        <Checkbox checked={data.confirm_duplicate_pib} onChange={(e) => setData('confirm_duplicate_pib', e.target.checked)} />
                        {t('Create anyway: another branch of the same company')}
                    </label>
                )}
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

            <PrimaryButton disabled={processing}>{create ? t('Create client') : t('Save')}</PrimaryButton>
        </form>
    );
}
