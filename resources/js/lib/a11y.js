/**
 * aria props that tie a control to its validation message (rendered by FormField / InputError with
 * the id `<id>-error`). Spread them on the control: `<TextInput id="name" {...fieldA11y('name', error)} />`.
 */
export const errorId = (id) => `${id}-error`;

export function fieldA11y(id, error) {
    return error ? { 'aria-invalid': true, 'aria-describedby': errorId(id) } : {};
}
