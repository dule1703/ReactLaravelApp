import { describe, expect, it } from 'vitest';
import { errorId, fieldA11y } from './a11y';

describe('fieldA11y', () => {
    it('adds nothing while the field is valid', () => {
        expect(fieldA11y('name', undefined)).toEqual({});
    });

    it('points the control at its error message', () => {
        expect(fieldA11y('name', 'Required')).toEqual({ 'aria-invalid': true, 'aria-describedby': 'name-error' });
        expect(errorId('name')).toBe('name-error');
    });
});
