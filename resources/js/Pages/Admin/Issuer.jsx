import { fieldA11y } from '@/lib/a11y';
import FormField from '@/Components/FormField';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';

export default function Issuer({ issuer }) {
    const { data, setData, patch, errors, processing } = useForm(issuer);

    const submit = (e) => {
        e.preventDefault();
        patch(route('issuer.update'), { preserveScroll: true });
    };

    const field = (name, label, props = {}) => (
        <FormField id={`issuer_${name}`} label={label} error={errors[name]}>
            <TextInput
                id={`issuer_${name}`}
                {...fieldA11y(`issuer_${name}`, errors[name])}
                className="mt-1 block w-full"
                value={data[name]}
                onChange={(e) => setData(name, e.target.value)}
                {...props}
            />
        </FormField>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-ink">{t('Offer issuer')}</h2>}>
            <Head title={t('Offer issuer')} />

            <div className="py-8">
                <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-4 shadow sm:p-6">
                        <p className="text-sm text-gray-600">
                            {t('These details are printed in the header of the offer PDF. They are copied into an offer when it is made: changing them does not change offers that already exist.')}
                        </p>
                        {field('name', t('Name of the issuer'), { required: true, maxLength: 150 })}
                        {field('address', t('Address'), { maxLength: 150 })}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {field('postal_code', t('Postal code'), { inputMode: 'numeric', maxLength: 5 })}
                            {field('city', t('City'), { maxLength: 100 })}
                        </div>
                        {field('pib', 'PIB', { inputMode: 'numeric', maxLength: 9 })}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {field('phone', t('Phone'), { maxLength: 30 })}
                            {field('email', t('Email'), { type: 'email', maxLength: 150 })}
                        </div>
                        <PrimaryButton disabled={processing}>{t('Save')}</PrimaryButton>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
