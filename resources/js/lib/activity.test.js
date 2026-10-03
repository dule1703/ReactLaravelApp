import { describe, expect, it } from 'vitest';
import { activityFieldLabel } from './activity';

describe('activityFieldLabel()', () => {
    it('prefers the entity-specific label', () => {
        expect(activityFieldLabel('transmission.updated', 'type')).toBe('Tip menjača');
        expect(activityFieldLabel('car_model.created', 'name')).toBe('Naziv');
        expect(activityFieldLabel('equipment_item.updated', 'name')).toBe('Naziv');
    });

    it('falls back to the generic label of the field', () => {
        expect(activityFieldLabel('user.updated', 'name')).toBe('Ime i prezime');
        expect(activityFieldLabel('client_profile.updated', 'type')).toBe('Tip klijenta');
        expect(activityFieldLabel('version.updated', 'is_active')).toBe('Aktivno');
    });

    it('falls back to the raw field name when nothing matches', () => {
        expect(activityFieldLabel('version.updated', 'unknown_field')).toBe('unknown_field');
        expect(activityFieldLabel(undefined, 'unknown_field')).toBe('unknown_field');
    });
});
