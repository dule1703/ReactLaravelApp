import translations from '../../../lang/sr_Latn.json';

/**
 * Translate a UI string. Keys are the English source strings; lang/sr_Latn.json is the single
 * source of truth shared with PHP (`__()`). Unknown keys fall back to the key itself.
 * Replacements use Laravel's `:name` syntax.
 */
export function t(key, replacements = {}) {
    let text = translations[key] ?? key;

    for (const [name, value] of Object.entries(replacements)) {
        text = text.replaceAll(`:${name}`, value);
    }

    return text;
}

/**
 * Translate a dynamic key (e.g. an action code from the server). Unlike t(), a missing
 * key falls back to the given text instead of showing the raw key.
 */
export function tOr(key, fallback) {
    return translations[key] ?? fallback;
}
