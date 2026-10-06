import { Link } from '@inertiajs/react';

// A link to an admin screen with a short description, for the Administration page.
export default function AdminLinkCard({ routeName, title, description }) {
    return (
        <Link
            href={route(routeName)}
            className="block rounded-lg border border-gray-200 p-4 transition hover:border-brand-500 hover:bg-surface"
        >
            <span className="block font-semibold text-brand-700">{title}</span>
            <span className="mt-1 block text-sm text-gray-600">{description}</span>
        </Link>
    );
}
