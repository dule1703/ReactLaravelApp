import FormField from '@/Components/FormField';
import { t } from '@/lib/i18n';
import { useState } from 'react';

const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

// Upload / replace / remove of a catalog image (models, equipment). The browser only gives quick
// feedback; the server decides from the real content of the file (type, 2 MB, dimensions).
// Form data used: image (File|null) and remove_image (bool).
export default function ImageField({ id = 'image', currentUrl, alt, data, setData, error }) {
    const [clientError, setClientError] = useState(null);

    const choose = (e) => {
        const file = e.target.files?.[0] ?? null;
        setClientError(null);

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

        setData((current) => ({ ...current, image: file, remove_image: 0 }));
    };

    const shown = currentUrl && !data.remove_image ? currentUrl : null;

    return (
        <FormField
            id={id}
            label={t('Image')}
            error={clientError ?? error}
            hint={t('JPG, PNG or WEBP, up to 2 MB, from 400x250 to 4000x4000 pixels.')}
        >
            {shown && (
                <div className="mt-2 flex items-center gap-3">
                    <img src={shown} alt={alt} className="h-16 w-24 rounded bg-surface object-cover" />
                    <button
                        type="button"
                        onClick={() => setData((current) => ({ ...current, remove_image: 1, image: null }))}
                        className="text-sm font-medium text-danger hover:underline"
                    >
                        {t('Remove image')}
                    </button>
                </div>
            )}
            {currentUrl && data.remove_image && (
                <p className="mt-2 text-sm italic text-gray-600">
                    {t('The image will be removed when you save.')}{' '}
                    <button
                        type="button"
                        onClick={() => setData('remove_image', 0)}
                        className="font-medium text-brand-700 hover:underline"
                    >
                        {t('Undo')}
                    </button>
                </p>
            )}
            <input
                id={id}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="mt-2 block w-full text-sm"
                onChange={choose}
            />
        </FormField>
    );
}
