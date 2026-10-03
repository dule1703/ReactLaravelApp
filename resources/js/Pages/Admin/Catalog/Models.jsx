import CatalogCrud from '@/Components/CatalogCrud';
import FormField from '@/Components/FormField';
import TextInput from '@/Components/TextInput';
import CatalogLayout from '@/Layouts/CatalogLayout';
import { t } from '@/lib/i18n';
import { useState } from 'react';

const PLACEHOLDER = '/images/catalog/car-placeholder.svg';
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

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
    const [clientError, setClientError] = useState(null);

    const chooseFile = (e) => {
        const file = e.target.files?.[0] ?? null;
        setClientError(null);

        // Quick feedback only; the server decides from the real file content.
        if (file && !ALLOWED_TYPES.includes(file.type)) {
            setClientError(t('The image must be a JPG, PNG or WEBP file.'));
        } else if (file && file.size > MAX_IMAGE_BYTES) {
            setClientError(t('The image is larger than the allowed 2 MB.'));
        }

        if (file && (!ALLOWED_TYPES.includes(file.type) || file.size > MAX_IMAGE_BYTES)) {
            e.target.value = '';
            setData('image', null);

            return;
        }

        setData((current) => ({ ...current, image: file, remove_image: false }));
    };

    const toggleCategory = (id) =>
        setData(
            'category_ids',
            data.category_ids.includes(id) ? data.category_ids.filter((item) => item !== id) : [...data.category_ids, id],
        );

    const currentImage = row?.image_url && !data.remove_image ? row.image_url : null;

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

            <FormField
                id="sort_order"
                label={t('Order')}
                error={errors.sort_order}
                hint={t('Lower numbers come first. Leave empty for the next free number.')}
            >
                <TextInput
                    id="sort_order"
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

            <FormField
                id="image"
                label={t('Image')}
                error={clientError ?? errors.image}
                hint={t('JPG, PNG or WEBP, up to 2 MB, from 400x250 to 4000x4000 pixels.')}
            >
                {currentImage && (
                    <div className="mt-2 flex items-center gap-3">
                        <img src={currentImage} alt={row.name} className="h-16 w-24 rounded bg-surface object-cover" />
                        <button
                            type="button"
                            onClick={() => setData((current) => ({ ...current, remove_image: true, image: null }))}
                            className="text-sm font-medium text-danger hover:underline"
                        >
                            {t('Remove image')}
                        </button>
                    </div>
                )}
                {row?.image_url && data.remove_image && (
                    <p className="mt-2 text-sm italic text-gray-600">
                        {t('The image will be removed when you save.')}{' '}
                        <button
                            type="button"
                            onClick={() => setData('remove_image', false)}
                            className="font-medium text-brand-700 hover:underline"
                        >
                            {t('Undo')}
                        </button>
                    </p>
                )}
                <input
                    id="image"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="mt-2 block w-full text-sm"
                    onChange={chooseFile}
                />
            </FormField>
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
                    remove_image: false,
                })}
            />
        </CatalogLayout>
    );
}
