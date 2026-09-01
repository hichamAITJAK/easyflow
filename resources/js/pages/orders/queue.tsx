import { Head, router } from '@inertiajs/react';
import { Inbox, Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { DataTablePaginationServer } from '@/components/data-table/data-table-pagination-server';
import { DataTablePerPageSelect } from '@/components/data-table/data-table-per-page-select';
import { CreateShipmentDialog } from '@/components/orders/create-shipment-dialog';
import { OrderFormDialog } from '@/components/orders/order-form-dialog';
import { OrderQueueCard } from '@/components/orders/order-queue-card';
import { OrderQueuePane } from '@/components/orders/order-queue-pane';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { bucketDotColors } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import { index as ordersIndex } from '@/routes/orders';
import type {
    OrderBucketCounts,
    Order,
    OrderFilters,
    OrderQueueBucket,
    Paginated,
} from '@/types';
import type { DeliveryAccount } from '@/types/delivery';

const BUCKETS: { key: OrderQueueBucket; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'new', label: 'New' },
    { key: 'follow_up', label: 'Follow-up' },
    { key: 'confirmed', label: 'Confirmed' },
    { key: 'shipped', label: 'Shipped' },
];

/**
 * The confirmation agent's call-center queue (PRD: "the confirmation queue
 * is the product's core daily tool — optimize for speed and minimal round
 * trips"). Two columns on wide viewports — a scrollable card list and a
 * stationary detail+CTA pane — collapsing to list-then-full-screen-detail
 * below `lg`, the standard master-detail mobile pattern. Replaces the
 * admin's datatable entirely for this role (see OrderController::index).
 */
export default function OrdersQueue({
    orders,
    filters,
    bucketCounts,
    deliveryAccounts,
}: {
    orders: Paginated<Order>;
    filters: OrderFilters;
    bucketCounts: OrderBucketCounts;
    deliveryAccounts: DeliveryAccount[];
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const debouncedSearch = useDebouncedValue(search, 300);
    const isFirstSearchRun = useRef(true);
    const [shipmentOrder, setShipmentOrder] = useState<Order | null>(null);
    const [editOrder, setEditOrder] = useState<Order | null>(null);
    /**
     * Manual order entry. Kept separate from `editOrder` rather than reusing
     * it with a null order: the same dialog serves both, but "no order is
     * being edited" and "a new order is being written" are different states,
     * and collapsing them would make the dialog open on every deselect.
     */
    const [createOpen, setCreateOpen] = useState(false);

    const [manualSelectedId, setManualSelectedId] = useState<number | null>(
        () => {
            const fromUrl = new URLSearchParams(window.location.search).get(
                'order',
            );

            return fromUrl ? Number(fromUrl) : null;
        },
    );
    const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
    const [loadingDetail, setLoadingDetail] = useState(false);
    const [detailError, setDetailError] = useState(false);
    const [mobileDetailOpen, setMobileDetailOpen] = useState(false);
    const [retryToken, setRetryToken] = useState(0);
    /**
     * An order the agent just acted on, held selected even after it leaves
     * the visible list. Recording a status usually moves the order out of
     * the active bucket, and without this the "still in the list" test below
     * fails on the very next reload and the pane jumps to another customer.
     * Cleared as soon as the agent selects anything else or changes the list.
     */
    const [keepSelectedId, setKeepSelectedId] = useState<number | null>(null);

    // Whatever the agent explicitly picked, as long as it's still in the
    // current list — falling back to the first card otherwise (bucket
    // switch, search, or the previous selection no longer being in the
    // list all land here), so the pane is never left empty. Derived
    // directly from props/state during render instead of synced via an
    // effect, since "adjust selection when the list changes" is exactly
    // the kind of derived value React docs steer away from useEffect for.
    const selectedId =
        manualSelectedId !== null &&
        (keepSelectedId === manualSelectedId ||
            orders.data.some((order) => order.id === manualSelectedId))
            ? manualSelectedId
            : (orders.data[0]?.id ?? null);

    useEffect(() => {
        if (isFirstSearchRun.current) {
            isFirstSearchRun.current = false;

            return;
        }

        setKeepSelectedId(null);
        router.get(
            ordersIndex().url,
            { ...filters, search: debouncedSearch || undefined },
            { preserveState: true, preserveScroll: true },
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    // Keeps the URL bookmarkable/shareable without an Inertia visit — this
    // is genuinely syncing React state to an external system (the address
    // bar), not deriving one piece of state from another.
    useEffect(() => {
        const url = new URL(window.location.href);

        if (selectedId === null) {
            url.searchParams.delete('order');
        } else {
            url.searchParams.set('order', String(selectedId));
        }

        window.history.replaceState(window.history.state, '', url);
    }, [selectedId]);

    // Fetches the selected order's full detail (status_events, etc. — never
    // eager-loaded on the list) whenever the selection changes. The list
    // row is shown immediately via `optimisticSelectedOrder` below while
    // this is in flight, so switching orders never shows a blank pane.
    // `retryToken` lets the pane's Retry button re-run this same effect
    // without duplicating the fetch logic.
    useEffect(() => {
        if (selectedId === null) {
            return;
        }

        let cancelled = false;
        setLoadingDetail(true);
        setDetailError(false);

        fetch(OrderController.show.url(selectedId), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { order: Order } | null) => {
                if (cancelled) {
                    return;
                }

                if (data) {
                    setSelectedOrder(data.order);
                } else {
                    setDetailError(true);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setDetailError(true);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingDetail(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [selectedId, retryToken]);

    // Lets an agent move through the queue without reaching for the mouse —
    // arrow keys select the next/previous card, matching the "optimize for
    // speed" mandate for this screen. Skipped while typing in the search
    // box or while the mobile full-screen detail is open (there's no list
    // to navigate in that view).
    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            if (mobileDetailOpen || orders.data.length === 0) {
                return;
            }

            const target = event.target as HTMLElement | null;

            if (
                target &&
                ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
            ) {
                return;
            }

            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
                return;
            }

            event.preventDefault();

            const currentIndex = orders.data.findIndex(
                (order) => order.id === selectedId,
            );
            const delta = event.key === 'ArrowDown' ? 1 : -1;
            const nextIndex = Math.min(
                Math.max(currentIndex + delta, 0),
                orders.data.length - 1,
            );

            setManualSelectedId(orders.data[nextIndex].id);
            setKeepSelectedId(null);
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [orders.data, selectedId, mobileDetailOpen]);

    const optimisticSelectedOrder =
        orders.data.find((order) => order.id === selectedId) ?? null;
    const displayedOrder = selectedOrder ?? optimisticSelectedOrder;

    const switchBucket = (bucket: OrderQueueBucket) => {
        // Changing the list is an explicit move away, so a pinned order
        // stops overriding the "first card in the new list" default.
        setKeepSelectedId(null);
        router.get(
            ordersIndex().url,
            { ...filters, bucket: bucket === 'all' ? undefined : bucket },
            { preserveState: true, preserveScroll: true },
        );
    };

    const refetchSelected = () => {
        if (selectedId === null) {
            return;
        }

        fetch(OrderController.show.url(selectedId), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { order: Order } | null) => {
                if (data) {
                    setSelectedOrder(data.order);
                }
            })
            // Without this a rejected refetch was an unhandled rejection that
            // left stale data in the pane with no sign anything had failed.
            .catch(() => setDetailError(true));

        router.reload({ only: ['orders', 'bucketCounts'] });
    };

    /**
     * Recording an outcome keeps the agent on the same order — they often
     * set a status and then keep working the same customer (call again,
     * send WhatsApp, correct a mis-tap), so the pane must not move.
     *
     * Pinning the id is what makes that hold. The status change usually
     * moves the order out of the active bucket, so it drops out of
     * `orders.data` on the next reload; without an explicit pin `selectedId`
     * falls through to `orders.data[0]` and the pane jumps to an unrelated
     * order. `keepSelectedId` survives that by keeping the pane on the
     * fetched detail even once the card is gone from the list.
     */
    const handleStatusChanged = () => {
        if (selectedId !== null) {
            setManualSelectedId(selectedId);
            setKeepSelectedId(selectedId);
        }

        refetchSelected();
    };

    return (
        <>
            <Head title="My queue" />

            {/* dvh, not vh — a mobile browser's dynamic toolbar was clipping
                the bottom of the viewport, and the action rail is the one
                region that must never be unreachable. */}
            <div className="flex h-[calc(100dvh-6rem)] flex-col">
                <div className="flex min-h-0 flex-1 gap-3 p-3">
                    <Card
                        className={cn(
                            'w-full shrink-0 gap-0 overflow-hidden py-0 lg:w-96',
                            mobileDetailOpen && 'hidden lg:flex',
                        )}
                    >
                        {/* Pinned above the scroll area rather than scrolling
                            with it: the agent re-filters and re-searches
                            constantly, so both controls stay reachable no
                            matter how far down the queue they are. */}
                        <div className="shrink-0 space-y-2 border-b bg-card p-3">
                            <div className="flex items-center gap-2">
                                <div className="group relative flex-1">
                                    <Search
                                        className={cn(
                                            'absolute top-1/2 left-2.5 z-10 size-4 -translate-y-1/2 transition-colors',
                                            // Still marks an active query, but
                                            // in ink rather than accent: the
                                            // primary button now sits inches
                                            // away, and two accent marks in a
                                            // 24rem column read as two calls
                                            // to action instead of one control
                                            // and one status.
                                            search
                                                ? 'text-foreground'
                                                : 'text-muted-foreground',
                                        )}
                                    />
                                    <Input
                                        className="pl-8"
                                        // Name is deliberately absent: it's
                                        // encrypted at rest and genuinely not
                                        // searchable, so listing it would send
                                        // agents hunting for a customer by name
                                        // and getting nothing.
                                        placeholder="Reference, tracking, or full phone…"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        aria-label="Search queue"
                                    />
                                </div>

                                {/* Orders normally arrive from the connected
                                    stores, so this is the exception path: an
                                    agent taking an order over the phone or on
                                    WhatsApp. Pinned beside the search field so
                                    it is reachable at any scroll depth.

                                    The label is hidden below `sm` rather than
                                    dropped: the queue card is the full width
                                    of a phone, and "New" plus the field's own
                                    placeholder would leave neither legible.
                                    aria-label carries the full name in both
                                    layouts. */}
                                <Button
                                    type="button"
                                    onClick={() => setCreateOpen(true)}
                                    aria-label="New manual order"
                                    className="shrink-0"
                                >
                                    <Plus />
                                    <span className="hidden sm:inline">
                                        New order
                                    </span>
                                </Button>
                            </div>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={filters.bucket ?? 'all'}
                                onValueChange={(value) => {
                                    if (value) {
                                        switchBucket(
                                            value as OrderQueueBucket,
                                        );
                                    }
                                }}
                                // Negative margins let the chips bleed to the
                                // card's edges while `px-3` keeps the first and
                                // last one clear of them when scrolled.
                                className="-mx-3 w-auto flex-nowrap justify-start overflow-x-auto px-3"
                            >
                                {BUCKETS.map((bucket) => {
                                    const active =
                                        (filters.bucket ?? 'all') ===
                                        bucket.key;
                                    const dot = bucketDotColors[bucket.key];

                                    return (
                                        <ToggleGroupItem
                                            key={bucket.key}
                                            value={bucket.key}
                                            aria-label={bucket.label}
                                            // Selection reads as weight and
                                            // ink, not hue — the dot owns the
                                            // only color here, so it stays
                                            // legible in both states instead
                                            // of being overpainted by a solid
                                            // active fill.
                                            className="shrink-0 gap-1.5 rounded-full px-3 font-normal data-[state=on]:border-foreground/20 data-[state=on]:bg-foreground/[0.06] data-[state=on]:font-medium data-[state=on]:text-foreground"
                                        >
                                            {dot && (
                                                <span
                                                    aria-hidden
                                                    className={cn(
                                                        'size-1.5 shrink-0 rounded-full transition-opacity',
                                                        dot,
                                                        // Unselected chips dim
                                                        // their dot so the run
                                                        // reads as one quiet
                                                        // band with a single
                                                        // lit marker.
                                                        active
                                                            ? 'opacity-100'
                                                            : 'opacity-55',
                                                    )}
                                                />
                                            )}
                                            {bucket.label}
                                            {bucketCounts[bucket.key] > 0 && (
                                                <span
                                                    className={cn(
                                                        'text-[11px] tabular-nums',
                                                        active
                                                            ? 'text-foreground/70'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {bucketCounts[bucket.key]}
                                                </span>
                                            )}
                                        </ToggleGroupItem>
                                    );
                                })}
                            </ToggleGroup>
                        </div>

                        <div className="flex-1 overflow-y-auto">
                            {orders.data.length === 0 ? (
                                <Empty className="border-none py-12">
                                    <EmptyHeader>
                                        <EmptyMedia
                                            variant="icon"
                                            className="bg-primary/10 text-primary"
                                        >
                                            {/* A magnifier over "your queue
                                                is clear" read as a failed
                                                search; the empty state's icon
                                                should match its cause. */}
                                            {search ? (
                                                <Search />
                                            ) : (
                                                <Inbox />
                                            )}
                                        </EmptyMedia>
                                        {/* Three distinct states, not two: a
                                            search that found nothing, an
                                            emptied bucket (the agent's queue
                                            is clear — worth saying plainly),
                                            and a filtered bucket with no
                                            orders yet. */}
                                        <EmptyTitle>
                                            {search
                                                ? 'No matches'
                                                : (filters.bucket ?? 'all') ===
                                                    'all'
                                                  ? 'Your queue is clear'
                                                  : 'Nothing in this filter'}
                                        </EmptyTitle>
                                        <EmptyDescription>
                                            {search
                                                ? `No order matches “${search}”. Reference and tracking match on any part; a phone number has to be complete, and customer names aren't searchable.`
                                                : (filters.bucket ?? 'all') ===
                                                    'all'
                                                  ? 'No orders are assigned to you right now. New ones appear here automatically.'
                                                  : 'No orders have this status. Try another filter.'}
                                        </EmptyDescription>
                                    </EmptyHeader>
                                    {/* A zero-result search is a dead end
                                        without this — the agent had to
                                        manually clear the box to get back
                                        to a populated queue. A cleared queue
                                        is a different dead end: nothing to
                                        work, and the one useful thing left is
                                        entering an order that came in by
                                        phone. The search case keeps only its
                                        own recovery action so the empty state
                                        never offers two competing next steps. */}
                                    {search ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setSearch('')}
                                        >
                                            Clear search
                                        </Button>
                                    ) : (
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() => setCreateOpen(true)}
                                        >
                                            <Plus />
                                            New manual order
                                        </Button>
                                    )}
                                </Empty>
                            ) : (
                                // Arrow keys move the selection without moving
                                // DOM focus, so a screen reader needs the
                                // listbox contract to follow along —
                                // otherwise its cursor sits still while the
                                // selection and the whole detail pane change.
                                <div
                                    className="space-y-2 px-3 py-2 focus-visible:outline-none"
                                    role="listbox"
                                    tabIndex={0}
                                    aria-label="Order queue"
                                    aria-activedescendant={
                                        selectedId !== null
                                            ? `queue-order-${selectedId}`
                                            : undefined
                                    }
                                >
                                    {orders.data.map((order) => (
                                        <OrderQueueCard
                                            key={order.id}
                                            id={`queue-order-${order.id}`}
                                            order={order}
                                            selected={
                                                order.id === selectedId
                                            }
                                            onSelect={() => {
                                                setManualSelectedId(
                                                    order.id,
                                                );
                                                setKeepSelectedId(null);

                                                // Only the stacked layout
                                                // navigates to a detail
                                                // "screen" — at `lg` the pane
                                                // is already on screen beside
                                                // the list, and flipping this
                                                // would hide the filter bar
                                                // and kill arrow-key nav.
                                                if (
                                                    !window.matchMedia(
                                                        '(min-width: 1024px)',
                                                    ).matches
                                                ) {
                                                    setMobileDetailOpen(true);
                                                }
                                            }}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Rendered whenever there are orders, not only when
                            they span pages: the count is the useful half of
                            this bar for an agent working a queue ("how much
                            is left"), and gating the whole footer on
                            last_page > 1 hid it on exactly the single-page
                            case that is most common. The component drops the
                            page links on its own. */}
                        {orders.data.length > 0 && (
                            <div className="shrink-0 space-y-2 border-t px-3 py-2.5">
                                {/* Page links first, then the size control
                                    and the count beneath them — this column
                                    is 24rem wide, too narrow to sit all three
                                    on one line without wrapping raggedly. */}
                                <DataTablePaginationServer
                                    paginated={orders}
                                    noun={{ one: 'order', many: 'orders' }}
                                    linksOnly
                                    className="justify-center"
                                />
                                <div className="flex items-center gap-2">
                                    <DataTablePerPageSelect
                                        routeUrl={ordersIndex().url}
                                        query={filters}
                                        value={Number(filters.per_page ?? 20)}
                                        size="sm"
                                    />
                                    <DataTablePaginationServer
                                        paginated={orders}
                                        noun={{ one: 'order', many: 'orders' }}
                                        countOnly
                                        className="min-w-0 flex-1"
                                    />
                                </div>
                            </div>
                        )}
                    </Card>

                    <div
                        className={cn(
                            'flex-1 overflow-hidden rounded-xl border bg-card',
                            !mobileDetailOpen && 'hidden lg:block',
                        )}
                    >
                        {selectedId === null ? (
                            <Empty className="h-full border-none">
                                <EmptyHeader>
                                    {/* "Get started" is filler on a screen
                                        the agent lives in all day. Say what
                                        the pane is for and how to fill it. */}
                                    <EmptyTitle>No order selected</EmptyTitle>
                                    <EmptyDescription>
                                        Pick an order from the queue to see
                                        its details and call the customer.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <OrderQueuePane
                                order={displayedOrder}
                                loading={loadingDetail && !displayedOrder}
                                error={detailError && !displayedOrder}
                                onRetry={() =>
                                    setRetryToken((token) => token + 1)
                                }
                                onBack={() => setMobileDetailOpen(false)}
                                onStatusChanged={handleStatusChanged}
                                onCreateShipment={setShipmentOrder}
                                onEdit={setEditOrder}
                            />
                        )}
                    </div>
                </div>
            </div>

            <CreateShipmentDialog
                open={shipmentOrder !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setShipmentOrder(null);
                        refetchSelected();
                    }
                }}
                order={shipmentOrder}
                deliveryAccounts={deliveryAccounts}
            />

            <OrderFormDialog
                open={editOrder !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditOrder(null);
                        refetchSelected();
                    }
                }}
                order={editOrder}
            />

            {/* A separate mount from the edit dialog above so each keeps its
                own `order` prop — one bound to the row being edited, this one
                permanently null so the form opens blank.

                Closing reloads the list rather than refetching the selected
                order: a new order is not the selected one and is not in the
                list yet, so `refetchSelected` would refresh the wrong record
                and leave the queue looking unchanged. */}
            <OrderFormDialog
                open={createOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        setCreateOpen(false);
                        router.reload({ only: ['orders', 'bucketCounts'] });
                    }
                }}
            />
        </>
    );
}
