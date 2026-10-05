import { useCallback, useRef } from 'react';

/**
 * Loads the JSON of the configurator (versions of a model, detail of a version) and keeps the
 * answers for the lifetime of the page. `fresh: true` skips the cache (editing an item re-reads
 * the current prices). A failed request is not cached and rejects, so the caller can show it.
 */
export default function useVersionCatalog() {
    const cache = useRef(new Map());

    const load = useCallback(async (url, fresh = false) => {
        if (!fresh && cache.current.has(url)) {
            return cache.current.get(url);
        }

        const { data } = await window.axios.get(url);
        cache.current.set(url, data);

        return data;
    }, []);

    const versions = useCallback((modelId, fresh) => load(route('offers.catalog.versions', modelId), fresh), [load]);
    const detail = useCallback((versionId, fresh) => load(route('offers.catalog.version', versionId), fresh), [load]);

    return { versions, detail };
}
