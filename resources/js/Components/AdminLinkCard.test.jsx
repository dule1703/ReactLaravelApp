import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it } from 'vitest';
import AdminLinkCard from './AdminLinkCard';

describe('AdminLinkCard', () => {
    afterEach(() => {
        delete globalThis.route;
    });

    it('links to the route it is given and shows the title and the description', () => {
        globalThis.route = (name) => ({ 'issuer.edit': '/admin/issuer' })[name];

        const html = renderToStaticMarkup(
            <AdminLinkCard routeName="issuer.edit" title="Izdavalac ponude" description="Podaci dilera" />,
        );

        expect(html).toContain('href="/admin/issuer"');
        expect(html).toContain('Izdavalac ponude');
        expect(html).toContain('Podaci dilera');
    });
});
