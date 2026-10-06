import { fieldA11y } from '@/lib/a11y';
import CatalogCrud from '@/Components/CatalogCrud';
import FormField from '@/Components/FormField';
import TextInput from '@/Components/TextInput';
import CatalogLayout from '@/Layouts/CatalogLayout';
import { t } from '@/lib/i18n';

const columns = [
    { header: t('Title'), cell: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { header: t('Slug'), cell: (row) => <span className="font-mono text-xs">{row.slug}</span> },
    { header: t('Models'), cell: (row) => row.models_count },
    { header: t('Order'), cell: (row) => row.sort_order },
];

function Fields({ data, setData, errors }) {
    return (
        <>
            <FormField id="name" label={t('Title')} error={errors.name}>
                <TextInput
                    id="name" {...fieldA11y('name', errors.name)}
                    className="mt-1 block w-full"
                    value={data.name}
                    maxLength={100}
                    required
                    isFocused
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

export default function Categories({ items, filters }) {
    return (
        <CatalogLayout active="categories">
            <CatalogCrud
                resource="catalog.categories"
                entity={t('Category')}
                items={items}
                filters={filters}
                columns={columns}
                Form={Fields}
                initialData={(row) => ({ name: row?.name ?? '', sort_order: row ? String(row.sort_order) : '' })}
            />
        </CatalogLayout>
    );
}
