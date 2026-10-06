import { fieldA11y } from '@/lib/a11y';
import CatalogCrud from '@/Components/CatalogCrud';
import FormField from '@/Components/FormField';
import ImageField from '@/Components/ImageField';
import TextInput from '@/Components/TextInput';
import CatalogLayout from '@/Layouts/CatalogLayout';
import { t } from '@/lib/i18n';

const PLACEHOLDER = '/images/catalog/car-placeholder.svg';

const columns = [
    {
        header: t('Image'),
        cell: (row) => (
            // Fixed size through CSS (the server has no image library to shrink files).
            <img
                src={row.image_url ?? PLACEHOLDER}
                alt={row.name}
                loading="lazy"
                className="h-10 w-16 rounded bg-surface object-cover"
            />
        ),
    },
    { header: t('Title'), cell: (row) => <span className="font-medium text-ink">{row.name}</span> },
    {
        header: t('Categories'),
        cell: (row) =>
            row.categories.length === 0 ? (
                <span className="text-gray-400">—</span>
            ) : (
                <div className="flex flex-wrap gap-1">
                    {row.categories.map((category) => (
                        <span
                            key={category.id}
                            title={category.is_active ? undefined : t('inactive')}
                            className={`rounded-full px-2 py-0.5 text-xs ${
                                category.is_active ? 'bg-brand-50 text-brand-800' : 'bg-gray-100 italic text-gray-500'
                            }`}
                        >
                            {category.name}
                        </span>
                    ))}
                </div>
            ),
    },
    { header: t('Slug'), cell: (row) => <span className="font-mono text-xs">{row.slug}</span> },
    { header: t('Trims'), cell: (row) => row.trims_count },
    { header: t('Versions'), cell: (row) => row.versions_count },
    { header: t('Order'), cell: (row) => row.sort_order },
];

function Fields({ data, setData, errors, row, context }) {
    const toggleCategory = (id) =>
        setData(
            'category_ids',
            data.category_ids.includes(id) ? data.category_ids.filter((item) => item !== id) : [...data.category_ids, id],
        );

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

            <fieldset>
                <legend className="block text-sm font-medium text-gray-700">{t('Categories')}</legend>
                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                    {context.categories.map((category) => (
                        <label key={category.id} className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={data.category_ids.includes(category.id)}
                                onChange={() => toggleCategory(category.id)}
                            />
                            <span className={category.is_active ? '' : 'italic text-gray-500'}>
                                {category.name}
                                {category.is_active ? '' : ` (${t('inactive')})`}
                            </span>
                        </label>
                    ))}
                </div>
                {errors.category_ids && <p className="mt-2 text-sm text-danger">{errors.category_ids}</p>}
            </fieldset>

            <ImageField currentUrl={row?.image_url} alt={row?.name ?? ''} data={data} setData={setData} error={errors.image} />
        </>
    );
}

export default function Models({ items, filters, categories }) {
    return (
        <CatalogLayout active="models">
            <CatalogCrud
                resource="catalog.models"
                entity={t('Model')}
                items={items}
                filters={filters}
                columns={columns}
                Form={Fields}
                formContext={{ categories }}
                multipart
                filterFields={[
                    {
                        name: 'category',
                        label: t('Category'),
                        options: categories.map((category) => ({ value: String(category.id), label: category.name })),
                    },
                ]}
                initialData={(row) => ({
                    name: row?.name ?? '',
                    sort_order: row ? String(row.sort_order) : '',
                    // Always sent: browsers send nothing for an empty selection.
                    sync_categories: 1,
                    category_ids: row ? row.categories.map((category) => category.id) : [],
                    image: null,
                    remove_image: 0,
                })}
            />
        </CatalogLayout>
    );
}
