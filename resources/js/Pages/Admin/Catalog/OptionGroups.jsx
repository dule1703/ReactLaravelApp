import { fieldA11y } from '@/lib/a11y';
import CatalogCrud from '@/Components/CatalogCrud';
import FormField from '@/Components/FormField';
import SelectInput from '@/Components/SelectInput';
import TextInput from '@/Components/TextInput';
import CatalogLayout from '@/Layouts/CatalogLayout';
import { t, tOr } from '@/lib/i18n';

const CATEGORIES = ['safety', 'comfort', 'exterior', 'interior', 'multimedia', 'driving'];
const categoryLabel = (category) => tOr(`equipment.category.${category}`, category);
const selectionLabel = (selection) => tOr(`option.selection.${selection}`, selection);

const columns = [
    { header: t('Title'), cell: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { header: t('Slug'), cell: (row) => <span className="font-mono text-xs">{row.slug}</span> },
    { header: t('Category'), cell: (row) => categoryLabel(row.category) },
    { header: t('Selection'), cell: (row) => selectionLabel(row.selection) },
    { header: t('Swatches'), cell: (row) => (row.uses_swatch ? t('Yes') : t('No')) },
    { header: t('Items'), cell: (row) => row.items_count },
    { header: t('Order'), cell: (row) => row.sort_order },
];

function Fields({ data, setData, errors, editing, row }) {
    // Changing the category of a group that has items changes the items too: needs a confirmation.
    const needsConfirmation = editing && row.items_count > 0 && data.category !== row.category;

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

            <FormField id="category" label={t('Category')} error={errors.category}>
                <SelectInput
                    id="category" {...fieldA11y('category', errors.category)}
                    className="mt-1 block w-full"
                    value={data.category}
                    onChange={(e) => setData((current) => ({ ...current, category: e.target.value, confirm_category_change: false }))}
                >
                    {CATEGORIES.map((category) => (
                        <option key={category} value={category}>
                            {categoryLabel(category)}
                        </option>
                    ))}
                </SelectInput>
                {needsConfirmation && (
                    <label className="mt-2 flex items-start gap-2 text-sm text-danger">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={data.confirm_category_change}
                            onChange={(e) => setData('confirm_category_change', e.target.checked)}
                        />
                        {t('I confirm: the category of :count items of this group changes too.', { count: row.items_count })}
                    </label>
                )}
            </FormField>

            <FormField
                id="selection"
                label={t('Selection')}
                error={errors.selection}
                hint={t('One of several: every trim has exactly one standard item and the others are surcharges.')}
            >
                <SelectInput
                    id="selection" {...fieldA11y('selection', errors.selection)}
                    className="mt-1 block w-full"
                    value={data.selection}
                    onChange={(e) => setData('selection', e.target.value)}
                >
                    {['single', 'multiple'].map((selection) => (
                        <option key={selection} value={selection}>
                            {selectionLabel(selection)}
                        </option>
                    ))}
                </SelectInput>
            </FormField>

            <div>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={data.uses_swatch}
                        onChange={(e) => setData('uses_swatch', e.target.checked)}
                    />
                    {t('Items have color swatches')}
                </label>
                {errors.uses_swatch && <p className="mt-1 text-sm text-danger">{errors.uses_swatch}</p>}
            </div>

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

export default function OptionGroups({ items, filters }) {
    return (
        <CatalogLayout active="option-groups">
            <CatalogCrud
                resource="catalog.option-groups"
                entity={t('Option group')}
                items={items}
                filters={filters}
                columns={columns}
                Form={Fields}
                initialData={(row) => ({
                    name: row?.name ?? '',
                    category: row?.category ?? 'exterior',
                    selection: row?.selection ?? 'single',
                    uses_swatch: row ? row.uses_swatch : false,
                    sort_order: row ? String(row.sort_order) : '',
                    confirm_category_change: false,
                })}
                usageWarning={(row) =>
                    row.items_count > 0
                        ? t('The group has :count items; changes of the category apply to all of them.', { count: row.items_count })
                        : null
                }
                rowNote={(row) =>
                    !row.is_active && row.items_count > 0 ? (
                        <p className="mt-1 text-xs italic text-yellow-800">{t('Its items are not offered in new offers.')}</p>
                    ) : null
                }
            />
        </CatalogLayout>
    );
}
