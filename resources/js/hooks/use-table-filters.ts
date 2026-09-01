import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Shared instant-apply filter state for a backend-driven DataTable: every
 * change is pushed to the server immediately via a query-string visit
 * (no separate "Apply" button), and `hasActiveFilters` tells the caller
 * whether to show a reset control.
 *
 * @param routeUrl  The index route to visit on every filter change.
 * @param filters   The server's current filter values (from Inertia props).
 * @param keys      Which filter keys count toward `hasActiveFilters` — pass
 *                  only the user-facing filter fields, not `sort`/`direction`/
 *                  `per_page`, so those don't make the reset button "stick".
 */
export function useTableFilters<TFilters extends Record<string, string | undefined>>(
    routeUrl: string,
    filters: TFilters,
    keys: (keyof TFilters)[],
) {
    const [draft, setDraft] = useState<TFilters>(filters);

    const hasActiveFilters = keys.some((key) => Boolean(draft[key]));

    const updateFilters = (patch: Partial<TFilters>) => {
        const next = { ...draft, ...patch };
        setDraft(next);
        router.get(routeUrl, next as Record<string, string>, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const resetFilters = () => {
        setDraft({} as TFilters);
        router.get(routeUrl, {}, { preserveState: true, preserveScroll: true });
    };

    return { draft, updateFilters, resetFilters, hasActiveFilters };
}
