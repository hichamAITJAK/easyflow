import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Sortable column header for a backend-driven DataTable: clicking cycles
 * `sort`/`direction` query params via a full Inertia visit — asc, then
 * desc, then back to unsorted (params cleared) — instead of TanStack's
 * client-side sort state, so sorting always applies to the whole result
 * set rather than just the current page.
 *
 * Pass `sortParam`/`directionParam` when a page carries more than one
 * server-driven table, so each one owns its own query-string keys instead
 * of fighting over a shared `sort`.
 */
export function DataTableColumnHeaderServer({
    title,
    sortKey,
    currentSort,
    currentDirection,
    routeUrl,
    query = {},
    className,
    sortParam = 'sort',
    directionParam = 'direction',
    pageParam = 'page',
}: {
    title: string;
    sortKey: string;
    currentSort?: string;
    currentDirection?: string;
    routeUrl: string;
    query?: Record<string, string | undefined>;
    className?: string;
    sortParam?: string;
    directionParam?: string;
    pageParam?: string;
}) {
    const isSorted = currentSort === sortKey;
    const sortedDirection = isSorted ? currentDirection : undefined;

    const nextSort =
        sortedDirection === 'asc'
            ? { [sortParam]: sortKey, [directionParam]: 'desc' }
            : sortedDirection === 'desc'
              ? { [sortParam]: undefined, [directionParam]: undefined }
              : { [sortParam]: sortKey, [directionParam]: 'asc' };

    const toggleSort = () => {
        router.get(
            routeUrl,
            // Re-sorting reorders the whole result set, so the old page
            // number no longer points at anything meaningful.
            { ...query, ...nextSort, [pageParam]: undefined },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <Button
            variant="ghost"
            size="sm"
            className={cn('-ml-3 h-8', className)}
            onClick={toggleSort}
        >
            {title}
            {sortedDirection === 'asc' ? (
                <ArrowUp />
            ) : sortedDirection === 'desc' ? (
                <ArrowDown />
            ) : (
                <ChevronsUpDown />
            )}
        </Button>
    );
}
