import { Link } from '@inertiajs/react';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
} from '@/components/ui/pagination';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

/**
 * Pagination footer for a Laravel-paginated (server-driven) resource, built
 * on shadcn's Pagination primitives and rendered from the paginator's
 * `links` array via Inertia `Link`s.
 *
 * shadcn's PaginationLink/Previous/Next don't support `asChild` (no Slot
 * composition), so their button styling is applied directly to Inertia
 * `Link`/`span` elements here instead of nesting through them.
 */
export function DataTablePaginationServer<TData>({
    paginated,
    /**
     * What the rows are, for the count label. Defaults to the datatable's
     * generic "entries"; pass a noun where the surface has one ("order") so
     * the footer reads in the product's own language.
     */
    noun,
    className,
    /** Render only the page links, for a footer that stacks its two halves. */
    linksOnly = false,
    /** Render only the count, the other half of that split. */
    countOnly = false,
}: {
    paginated: Paginated<TData>;
    noun?: { one: string; many: string };
    className?: string;
    linksOnly?: boolean;
    countOnly?: boolean;
}) {
    const links = paginated.links;
    const previous = links[0];
    const next = links[links.length - 1];
    const pages = links.slice(1, -1);

    const one = noun?.one ?? 'entry';
    const many = noun?.many ?? 'entries';

    const onOnePage = paginated.last_page <= 1;

    // Nothing to draw: a links-only footer on a single-page result would
    // otherwise render an empty box that still eats its container's gap.
    if (linksOnly && onOnePage) {
        return null;
    }

    return (
        // flex-1 so this spreads across whatever the caller's footer row
        // leaves it, putting the count at one end and the links at the other.
        <div
            className={cn(
                'flex flex-1 flex-wrap items-center justify-between gap-2',
                className,
            )}
        >
            {/* A single page states the plain count — "Showing 1 to 7 of 7"
                is noise when the range is the whole set. */}
            {!linksOnly && (
                <span className="truncate text-sm text-muted-foreground">
                    {paginated.total === 0
                        ? `No ${many}`
                        : onOnePage
                          ? `${paginated.total} ${paginated.total === 1 ? one : many}`
                          : `Showing ${paginated.from} to ${paginated.to} of ${paginated.total} ${many}`}
                </span>
            )}

            {/* Pushed right only while the count shares the row. On its own
                (linksOnly) the caller decides the alignment — the queue
                centres it — and an unconditional ml-auto would override that
                caller's own justify-* class. */}
            {!countOnly && paginated.last_page > 1 && (
                <Pagination
                    className={cn('mr-0 w-auto', !linksOnly && 'ml-auto')}
                >
                    <PaginationContent>
                        <PaginationItem>
                            <Link
                                href={previous.url ?? '#'}
                                preserveScroll
                                preserveState
                                aria-label="Go to previous page"
                                aria-disabled={!previous.url}
                                className={cn(
                                    buttonVariants({
                                        variant: 'ghost',
                                        size: 'default',
                                    }),
                                    'gap-1 px-2.5 sm:pl-2.5',
                                    !previous.url &&
                                        'pointer-events-none opacity-50',
                                )}
                            >
                                <ChevronLeftIcon />
                                <span className="hidden sm:block">
                                    Previous
                                </span>
                            </Link>
                        </PaginationItem>

                        {pages.map((link, index) =>
                            link.url === null ? (
                                <PaginationItem key={index}>
                                    <PaginationEllipsis />
                                </PaginationItem>
                            ) : (
                                <PaginationItem key={index}>
                                    <Link
                                        href={link.url}
                                        preserveScroll
                                        preserveState
                                        aria-current={
                                            link.active ? 'page' : undefined
                                        }
                                        className={cn(
                                            buttonVariants({
                                                variant: link.active
                                                    ? 'outline'
                                                    : 'ghost',
                                                size: 'icon',
                                            }),
                                        )}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                </PaginationItem>
                            ),
                        )}

                        <PaginationItem>
                            <Link
                                href={next.url ?? '#'}
                                preserveScroll
                                preserveState
                                aria-label="Go to next page"
                                aria-disabled={!next.url}
                                className={cn(
                                    buttonVariants({
                                        variant: 'ghost',
                                        size: 'default',
                                    }),
                                    'gap-1 px-2.5 sm:pr-2.5',
                                    !next.url &&
                                        'pointer-events-none opacity-50',
                                )}
                            >
                                <span className="hidden sm:block">Next</span>
                                <ChevronRightIcon />
                            </Link>
                        </PaginationItem>
                    </PaginationContent>
                </Pagination>
            )}
        </div>
    );
}
