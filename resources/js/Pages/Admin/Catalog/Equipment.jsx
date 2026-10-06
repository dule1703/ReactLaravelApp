import { fieldA11y } from '@/lib/a11y';
import CatalogCrud from '@/Components/CatalogCrud';
import FormField from '@/Components/FormField';
import ImageField from '@/Components/ImageField';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import CatalogLayout from '@/Layouts/CatalogLayout';
import { t, tOr } from '@/lib/i18n';

const PLACEHOLDER = '/images/catalog/item-placeholder.svg';
const categoryLabel = (category) => tOr(`equipment.category.${category}`, category);

const columns = [
    {
        header: t('Image'),
        cell: (row) => (
            // Fixed size through CSS (the server has no image library to shrink files).
            <img
                src={row.image_url ?? PLACEHOLDER}
                alt={row.name}
                loading="lazy"
                className="h-10 w-14 rounded bg-surface object-cover"
            />
        ),
    },
    { header: t('Title'), cell: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { header: t('Category'), cell: (row) => categoryLabel(row.category) },
    {
        header: t('Option group'),
        cell: (row) =>
            row.group_name ? (
                <span className="rounded-full bg-brand-50 px-2 py-0.5 text-xs text-brand-800">{row.group_name}</span>
            ) : (
                <span className="text-gray-400">—</span>
            ),
    },
    {
        header: t('Swatch'),
        cell: (row) =>
            row.swatch_hex ? (
                <span className="inline-flex items-center gap-2">
                    <span
                        role="img"
                        aria-label={row.swatch_hex}
                        title={row.swatch_hex}
                        className="inline-block h-5 w-5 rounded border border-gray-300"
                        style={{ backgroundColor: row.swatch_hex }}
                    />
                    <span className="font-mono text-xs">{row.swatch_hex}</span>
                </span>
            ) : (
                <span className="text-gray-400">—</span>
            ),
    },
    { header: t('On trims'), cell: (row) => t('In :count trims', { count: row.lines_count }) },
];

function Fields({ data, setData, errors, editing, row, context }) {
    const group = context.groups.find((candidate) => String(candidate.id) === String(data.group_id)) ?? null;
    // The group of an item that is already on some trim cannot change here (matrix 3.11).
    const groupLocked = editing && row.lines_count > 0;
    const deactivationLocked = editing && row.is_active && row.standard_single_lines > 0;

    const chooseGroup = (value) => {
        const next = context.groups.find((candidate) => String(candidate.id) === String(value)) ?? null;

        setData((current) => ({
            ...current,
            group_id: value,
            // A group fixes the category; without a group the swatch has no meaning.
            category: next ? next.category : current.category,
            swatch_hex: next?.uses_swatch ? current.swatch_hex : '',
        }));
    };

    return (
        <>
            <FormField id="name" label={t('Title')} error={errors.name}>
                <TextInput
                    id="name" {...fieldA11y('name', errors.name)}
                    className="mt-1 block w-full"
                    value={data.name}
                    maxLength={150}
                    required
                    isFocused
                    onChange={(e) => setData('name', e.target.value)}
                />
            </FormField>

            <FormField
                id="group_id"
                label={t('Option group')}
                error={errors.group_id}
                hint={
                    groupLocked
                        ? t('The item is on :count trims; its group cannot be changed. Deactivate it or change it in the equipment matrix.', { count: row.lines_count })
                        : t('Colors, wheels, upholstery: one choice of several. Leave empty for an independent extra.')
                }
            >
                <SelectInput
                    id="group_id" {...fieldA11y('group_id', errors.group_id)}
                    className="mt-1 block w-full"
                    value={data.group_id}
                    disabled={groupLocked}
                    onChange={(e) => chooseGroup(e.target.value)}
                >
                    <option value="">{t('No group (independent extra)')}</option>
                    {context.groups.map((candidate) => (
                        <option key={candidate.id} value={candidate.id}>
                            {candidate.is_active ? candidate.name : `${candidate.name} (${t('inactive')})`}
                        </option>
                    ))}
                </SelectInput>
            </FormField>

            <FormField
                id="category"
                label={t('Category')}
                error={errors.category}
                hint={group ? t('Fixed by the group.') : null}
            >
                <SelectInput
                    id="category" {...fieldA11y('category', errors.category)}
                    className="mt-1 block w-full"
                    value={data.category}
                    disabled={group !== null}
                    onChange={(e) => setData('category', e.target.value)}
                >
                    {context.categories.map((category) => (
                        <option key={category} value={category}>
                            {categoryLabel(category)}
                        </option>
                    ))}
                </SelectInput>
            </FormField>

            {group?.uses_swatch && (
                <FormField id="swatch_hex" label={t('Color swatch')} error={errors.swatch_hex} hint={t('Color as #RRGGBB.')}>
                    <div className="mt-1 flex items-center gap-3">
                        <input
                            type="color"
                            aria-label={t('Pick a color')}
                            className="h-10 w-14 rounded border border-gray-300"
                            value={/^#[0-9A-Fa-f]{6}$/.test(data.swatch_hex) ? data.swatch_hex : '#ffffff'}
                            onChange={(e) => setData('swatch_hex', e.target.value.toUpperCase())}
                        />
                        <TextInput
                            id="swatch_hex" {...fieldA11y('swatch_hex', errors.swatch_hex)}
                            className="block w-32 font-mono"
                            maxLength={7}
                            value={data.swatch_hex}
                            onChange={(e) => setData('swatch_hex', e.target.value)}
                        />
                    </div>
                </FormField>
            )}

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

            <div>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={Boolean(data.is_active)}
                        disabled={deactivationLocked}
                        onChange={(e) => setData('is_active', e.target.checked ? 1 : 0)}
                    />
                    {t('Active')}
                </label>
                {deactivationLocked && (
                    <p className="mt-1 text-sm text-gray-600">
                        {t('The item is the standard item of a single-choice group on :count trims; replace it in the equipment matrix before deactivating it.', { count: row.standard_single_lines })}
                    </p>
                )}
                {errors.is_active && <p className="mt-1 text-sm text-danger">{errors.is_active}</p>}
            </div>

            <ImageField currentUrl={row?.image_url} alt={row?.name ?? ''} data={data} setData={setData} error={errors.image} />
        </>
    );
}

export default function Equipment({ items, filters, categories, groups }) {
    return (
        <CatalogLayout active="equipment">
            <CatalogCrud
                resource="catalog.equipment"
                entity={t('Equipment item')}
                items={items}
                filters={filters}
                columns={columns}
                Form={Fields}
                formContext={{ groups, categories }}
                multipart
                initialData={(row) => ({
                    name: row?.name ?? '',
                    group_id: row?.group_id ? String(row.group_id) : '',
                    category: row?.category ?? categories[0],
                    swatch_hex: row?.swatch_hex ?? '',
                    sort_order: row ? String(row.sort_order) : '',
                    is_active: row ? (row.is_active ? 1 : 0) : 1,
                    image: null,
                    remove_image: 0,
                })}
                usageWarning={(row) =>
                    row.lines_count > 0
                        ? t('Used on :count trims; the change applies to all of them.', { count: row.lines_count })
                        : null
                }
                deactivationBlock={(row) =>
                    row.standard_single_lines > 0
                        ? t('The item is the standard item of a single-choice group on :count trims; replace it in the equipment matrix before deactivating it.', { count: row.standard_single_lines })
                        : null
                }
                deleteNote={t('Note: the seeder may bring a deleted row back (firstOrCreate); deactivation is recommended.')}
                filterFields={[
                    {
                        name: 'category',
                        label: t('Category'),
                        options: categories.map((category) => ({ value: category, label: categoryLabel(category) })),
                    },
                    {
                        name: 'group',
                        label: t('Option group'),
                        options: [
                            { value: 'none', label: t('No group (independent extra)') },
                            ...groups.map((group) => ({ value: String(group.id), label: group.name })),
                        ],
                    },
                    {
                        name: 'status',
                        label: t('Status'),
                        options: [
                            { value: 'active', label: t('Active') },
                            { value: 'inactive', label: t('Inactive') },
                        ],
                    },
                ]}
                rowNote={(row) =>
                    row.group_inactive ? (
                        <p className="mt-1 text-xs italic text-yellow-800">{t('Not offered: the option group is inactive.')}</p>
                    ) : null
                }
            />
        </CatalogLayout>
    );
}
