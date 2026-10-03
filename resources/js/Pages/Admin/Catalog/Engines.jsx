import CatalogCrud from '@/Components/CatalogCrud';
import FormField from '@/Components/FormField';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import CatalogLayout from '@/Layouts/CatalogLayout';
import { t, tOr } from '@/lib/i18n';

const fuelLabel = (fuel) => tOr(`fuel.${fuel}`, fuel);

const columns = [
    { header: t('Title'), cell: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { header: t('Fuel'), cell: (row) => fuelLabel(row.fuel_type) },
    { header: t('Power (kW)'), cell: (row) => `${row.power_kw} kW` },
    { header: t('Versions'), cell: (row) => row.versions_count },
];

function Fields({ data, setData, errors, context }) {
    return (
        <>
            <FormField id="name" label={t('Title')} error={errors.name}>
                <TextInput
                    id="name"
                    className="mt-1 block w-full"
                    value={data.name}
                    maxLength={100}
                    required
                    isFocused
                    onChange={(e) => setData('name', e.target.value)}
                />
            </FormField>
            <FormField id="fuel_type" label={t('Fuel')} error={errors.fuel_type}>
                <SelectInput
                    id="fuel_type"
                    className="mt-1 block w-full"
                    value={data.fuel_type}
                    required
                    onChange={(e) => setData('fuel_type', e.target.value)}
                >
                    <option value="">{t('Choose')}</option>
                    {context.fuelTypes.map((fuel) => (
                        <option key={fuel} value={fuel}>
                            {fuelLabel(fuel)}
                        </option>
                    ))}
                </SelectInput>
            </FormField>
            <FormField id="power_kw" label={t('Power (kW)')} error={errors.power_kw} hint={t('From 20 to 1000 kW.')}>
                <TextInput
                    id="power_kw"
                    className="mt-1 block w-32"
                    inputMode="numeric"
                    value={data.power_kw}
                    required
                    onChange={(e) => setData('power_kw', e.target.value)}
                />
            </FormField>
        </>
    );
}

export default function Engines({ items, filters, fuelTypes }) {
    return (
        <CatalogLayout active="engines">
            <CatalogCrud
                resource="catalog.engines"
                entity={t('Engine')}
                items={items}
                filters={filters}
                columns={columns}
                Form={Fields}
                formContext={{ fuelTypes }}
                initialData={(row) => ({
                    name: row?.name ?? '',
                    fuel_type: row?.fuel_type ?? '',
                    power_kw: row ? String(row.power_kw) : '',
                })}
                usageWarning={(row) =>
                    row.versions_count > 0
                        ? t('Used in :count versions; the change applies to all of them.', { count: row.versions_count })
                        : null
                }
            />
        </CatalogLayout>
    );
}
