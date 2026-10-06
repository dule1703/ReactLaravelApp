import { fieldA11y } from '@/lib/a11y';
import CatalogCrud from '@/Components/CatalogCrud';
import FormField from '@/Components/FormField';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import CatalogLayout from '@/Layouts/CatalogLayout';
import { t } from '@/lib/i18n';

const columns = [
    { header: t('Title'), cell: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { header: t('Car model'), cell: (row) => row.model_name },
    { header: t('Versions'), cell: (row) => row.versions_count },
    { header: t('Order'), cell: (row) => row.sort_order },
];

// The car model is chosen when the trim is created and never changes afterwards.
function Fields({ data, setData, errors, editing, row, context }) {
    return (
        <>
            <FormField id="car_model_id" label={t('Car model')} error={errors.car_model_id}>
                {editing ? (
                    <p className="mt-1 text-sm text-ink">{row.model_name}</p>
                ) : (
                    <SelectInput
                        id="car_model_id" {...fieldA11y('car_model_id', errors.car_model_id)}
                        className="mt-1 block w-full"
                        value={data.car_model_id}
                        required
                        onChange={(e) => setData('car_model_id', e.target.value)}
                    >
                        <option value="">{t('Choose a model')}</option>
                        {context.models.map((model) => (
                            <option key={model.id} value={model.id}>
                                {model.name}
                                {model.is_active ? '' : ` (${t('inactive')})`}
                            </option>
                        ))}
                    </SelectInput>
                )}
            </FormField>
            <FormField id="name" label={t('Title')} error={errors.name}>
                <TextInput
                    id="name" {...fieldA11y('name', errors.name)}
                    className="mt-1 block w-full"
                    value={data.name}
                    maxLength={100}
                    required
                    onChange={(e) => setData('name', e.target.value)}
                />
            </FormField>
            <FormField
                id="sort_order"
                label={t('Order')}
                error={errors.sort_order}
                hint={t('Lower numbers come first. Leave empty for the next free number.')}
            >
                <TextInput
                    id="sort_order" {...fieldA11y('sort_order', errors.sort_order)}
                    className="mt-1 block w-32"
                    inputMode="numeric"
                    value={data.sort_order}
                    onChange={(e) => setData('sort_order', e.target.value)}
                />
            </FormField>
        </>
    );
}

export default function Trims({ items, filters, models }) {
    return (
        <CatalogLayout active="trims">
            <CatalogCrud
                resource="catalog.trims"
                entity={t('Trim')}
                items={items}
                filters={filters}
                columns={columns}
                Form={Fields}
                formContext={{ models }}
                initialData={(row) => ({
                    car_model_id: row ? String(row.car_model_id) : '',
                    name: row?.name ?? '',
                    sort_order: row ? String(row.sort_order) : '',
                })}
                usageWarning={(row) =>
                    row.versions_count > 0
                        ? t('Used in :count versions; the change applies to all of them.', { count: row.versions_count })
                        : null
                }
                rowNote={(row) =>
                    row.unavailable_reason === 'model_inactive' ? (
                        <p className="mt-1 text-xs italic text-yellow-800">{t('Not offered: the car model is inactive.')}</p>
                    ) : null
                }
            />
        </CatalogLayout>
    );
}
