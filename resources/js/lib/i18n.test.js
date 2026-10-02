import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import translations from '../../../lang/sr_Latn.json';
import { t } from './i18n';

const jsRoot = path.resolve(__dirname, '..');

function jsxFiles(dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return jsxFiles(full);
        return entry.name.endsWith('.jsx') ? [full] : [];
    });
}

describe('t()', () => {
    it('returns the Serbian translation', () => {
        expect(t('Log in')).toBe('Prijava');
    });

    it('falls back to the key for unknown strings', () => {
        expect(t('Not in the file')).toBe('Not in the file');
    });

    it('replaces :name placeholders', () => {
        expect(t('Hello :name', { name: 'Ana' })).toBe('Hello Ana');
    });
});

describe('lang/sr_Latn.json', () => {
    it('contains every literal key passed to t() in the JSX files', () => {
        const missing = [];

        for (const file of jsxFiles(jsRoot)) {
            const source = fs.readFileSync(file, 'utf8');
            for (const match of source.matchAll(/\bt\(\s*'((?:[^'\\]|\\.)*)'/g)) {
                const key = match[1].replace(/\\'/g, "'");
                if (!(key in translations)) missing.push(`${path.basename(file)}: ${key}`);
            }
        }

        expect(missing).toEqual([]);
    });
});
