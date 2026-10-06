import SecondaryButton from '@/Components/SecondaryButton';
import { moveActive } from '@/lib/configurator';
import { t } from '@/lib/i18n';
import { useEffect, useId, useRef, useState } from 'react';

const MIN_CHARS = 2;
const DEBOUNCE_MS = 300;

function ProfileLink({ client }) {
    return (
        <a
            href={route('clients.edit', client.id)}
            target="_blank"
            rel="noopener"
            className="font-medium text-brand-700 underline"
        >
            {t('Complete the client profile')}
        </a>
    );
}

/**
 * The admin chooses the client the offer is made for: an accessible combobox that searches by name
 * or email (debounced, at least 2 characters) and then shows the chosen client with a "Change"
 * button. A client whose profile is incomplete can be chosen but is flagged at once (and the offer
 * cannot be saved); the server checks it again. Only what tells two clients apart is shown.
 */
export default function ClientPicker({ selected, onSelect, serverError = null }) {
    const id = useId();
    const listId = `${id}-list`;
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);
    const [state, setState] = useState('idle'); // idle | loading | done | error
    const latest = useRef(0);
    const trimmed = query.trim();

    // Debounced search; an answer of an older request never overwrites a newer one.
    useEffect(() => {
        if (trimmed.length < MIN_CHARS) {
            latest.current += 1;
            setResults([]);
            setState('idle');

            return undefined;
        }

        const timer = setTimeout(async () => {
            const request = ++latest.current;
            setState('loading');

            try {
                const { data } = await window.axios.get(route('offers.catalog.clients'), { params: { q: trimmed } });

                if (request === latest.current) {
                    setResults(data.clients);
                    setActive(-1);
                    setState('done');
                }
            } catch {
                if (request === latest.current) {
                    setResults([]);
                    setState('error');
                }
            }
        }, DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [trimmed]);

    const choose = (client) => {
        onSelect(client);
        setQuery('');
        setResults([]);
        setOpen(false);
        setActive(-1);
    };

    const onKeyDown = (event) => {
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            setOpen(true);
            setActive((index) => moveActive(index, results.length, event.key));
        } else if (event.key === 'Enter' && open && active >= 0 && results[active]) {
            event.preventDefault();
            choose(results[active]);
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    if (selected) {
        return (
            <div>
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-brand-500 bg-brand-50 px-3 py-2">
                    <div className="min-w-0">
                        <p className="font-semibold text-ink">{selected.name}</p>
                        <p className="text-sm text-gray-600">
                            {[selected.city, selected.email].filter(Boolean).join(' · ')}
                        </p>
                    </div>
                    <SecondaryButton onClick={() => onSelect(null)}>{t('Change')}</SecondaryButton>
                </div>

                {selected.complete !== true && (
                    <p role="alert" className="mt-2 text-sm text-danger">
                        {t('The profile of this client is incomplete. Complete it to make an offer.')} <ProfileLink client={selected} />
                    </p>
                )}
                {serverError && selected.complete === true && (
                    <p role="alert" className="mt-2 text-sm text-danger">
                        {serverError} <ProfileLink client={selected} />
                    </p>
                )}
            </div>
        );
    }

    const expanded = open && trimmed.length >= MIN_CHARS;

    return (
        <div className="relative">
            <label htmlFor={`${id}-input`} className="block text-sm font-medium text-gray-700">
                {t('Search clients by name or email')}
            </label>
            <input
                id={`${id}-input`}
                type="search"
                role="combobox"
                aria-expanded={expanded}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={active >= 0 ? `${id}-option-${active}` : undefined}
                autoComplete="off"
                maxLength={100}
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onKeyDown={onKeyDown}
                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500"
            />
            <p className="mt-1 text-xs text-gray-500" aria-live="polite">
                {trimmed.length < MIN_CHARS && t('Type at least :count characters.', { count: MIN_CHARS })}
                {state === 'loading' && t('Searching…')}
                {state === 'error' && <span className="text-danger">{t('The clients could not be loaded. Try again.')}</span>}
                {state === 'done' && results.length === 0 && t('No clients found.')}
            </p>

            <ul
                id={listId}
                role="listbox"
                hidden={!expanded || results.length === 0}
                className="absolute z-10 mt-1 max-h-72 w-full overflow-auto rounded-md border border-gray-200 bg-white shadow-lg"
            >
                {results.map((client, index) => (
                    <li
                        key={client.id}
                        id={`${id}-option-${index}`}
                        role="option"
                        aria-selected={index === active}
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => choose(client)}
                        onMouseEnter={() => setActive(index)}
                        className={`cursor-pointer px-3 py-2 ${index === active ? 'bg-brand-50' : ''}`}
                    >
                        <p className="text-sm font-medium text-ink">{client.name}</p>
                        <p className="text-xs text-gray-600">{[client.city, client.email].filter(Boolean).join(' · ')}</p>
                        {!client.complete && <p className="text-xs text-danger">{t('Profile incomplete')}</p>}
                    </li>
                ))}
            </ul>

            {serverError && (
                <p role="alert" className="mt-2 text-sm text-danger">
                    {serverError}
                </p>
            )}
        </div>
    );
}
