/** How long a request runs before the content is dimmed, so short requests do not flicker. */
export const BUSY_DELAY_MS = 150;

/**
 * Whether an Inertia visit should dim the current page: only a GET to the same path (a search or a
 * filter reloading the list). A visit to another page, or a form submit, must not dim this one.
 */
export function dimsCurrentPage(visit, currentPathname) {
    return visit?.method === 'get' && visit?.url?.pathname === currentPathname;
}
