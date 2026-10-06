import { describe, expect, it } from 'vitest';
import { dimsCurrentPage } from './navigation';

const visit = (method, path) => ({ method, url: new URL(path, 'http://localhost') });

describe('dimsCurrentPage', () => {
    it('dims for a GET to the same path, whatever the query', () => {
        expect(dimsCurrentPage(visit('get', '/offers?q=a'), '/offers')).toBe(true);
    });

    it('does not dim for another page', () => {
        expect(dimsCurrentPage(visit('get', '/clients'), '/offers')).toBe(false);
    });

    it('does not dim for a form submit to the same path', () => {
        expect(dimsCurrentPage(visit('post', '/offers'), '/offers')).toBe(false);
    });

    it('does not dim without a visit', () => {
        expect(dimsCurrentPage(undefined, '/offers')).toBe(false);
    });
});
