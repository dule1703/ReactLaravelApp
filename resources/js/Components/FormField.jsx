import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { errorId } from '@/lib/a11y';

// Label + control + validation error. The control gets aria-invalid / aria-describedby from
// fieldA11y(id, error) (explicitly, on the control), the message carries the matching id.
export default function FormField({ id, label, error, hint, children }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            {children}
            {hint && <p className="mt-1 text-sm text-gray-600">{hint}</p>}
            <InputError id={errorId(id)} className="mt-2" message={error} />
        </div>
    );
}
