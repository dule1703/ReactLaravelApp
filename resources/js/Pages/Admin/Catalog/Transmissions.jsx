import CatalogCrud from '@/Components/CatalogCrud';
import FormField from '@/Components/FormField';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import CatalogLayout from '@/Layouts/CatalogLayout';
import { t, tOr } from '@/lib/i18n';

const typeLabel = (type) => tOr(`transmission.type.${type}`, type);
const driveLabel = (drive) => tOr(`drive.${drive}`, drive);

const columns = [
    { header: t('Title'), cell: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { header: t('Type'), cell: (row) => typeLabel(row.type) },
    { header: t('Drive'), cell: (row) => driveLabel(row.drive) },
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
            <FormField id="type" label={t('Type')} error={errors.type}>
                <SelectInput
                    id="type"
                    className="mt-1 block w-full"
                    value={data.type}
                    required
                    onChange={(e) => setData('type', e.target.value)}
                >
                    <option value="">{t('Choose')}</option>
                    {context.types.map((type) => (
                        <option key={type} value={type}>
                            {typeLabel(type)}
                        </option>
                    ))}
                </SelectInput>
            </FormField>
            <FormField id="drive" label={t('Drive')} error={errors.drive}>
                <SelectInput
                    id="drive"
                    className="mt-1 block w-full"
                    value={data.drive}
                    required
                    onChange={(e) => setData('drive', e.target.value)}
                >
                    <option value="">{t('Choose')}</option>
                    {context.drives.map((drive) => (
                        <option key={drive} value={drive}>
                            {driveLabel(drive)}
                        </option>
                    ))}
                </SelectInput>
            </FormField>
        </>
    );
}

export default function Transmissions({ items, filters, types, drives }) {
    return (
        <CatalogLayout active="transmissions">
            <CatalogCrud
                resource="catalog.transmissions"
                entity={t('Transmission')}
                items={items}
                filters={filters}
                columns={columns}
                Form={Fields}
                formContext={{ types, drives }}
                initialData={(row) => ({
                    name: row?.name ?? '',
                    type: row?.type ?? '',
                    drive: row?.drive ?? '',
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
