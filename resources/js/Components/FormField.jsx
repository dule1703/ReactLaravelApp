import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';

// Label + control + validation error, wired together for screen readers.
export default function FormField({ id, label, error, hint, children }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            {children}
            {hint && <p className="mt-1 text-sm text-gray-600">{hint}</p>}
            <InputError className="mt-2" message={error} />
        </div>
    );
}
