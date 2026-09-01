import { router } from '@inertiajs/react';
import {
    Ban,
    Check,
    ChevronDown,
    ChevronLeft,
    Copy,
    Loader2,
    MoreHorizontal,
    Pencil,
    Phone,
    RotateCcw,
    ShieldBan,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { updateStatus } from '@/actions/App/Http/Controllers/Orders/OrderController';
import { WhatsAppIcon } from '@/components/icons/whatsapp-icon';
import { BlacklistOrderDialog } from '@/components/orders/blacklist-order-dialog';
import { CancelOrderDialog } from '@/components/orders/cancel-order-dialog';
import { OrderDetail } from '@/components/orders/order-detail';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Command,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyTitle } from '@/components/ui/empty';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import {
    confirmationStatusDotColors,
    confirmationStatusLabels,
    CONFIRMATION_STATUS_GROUPS,
    QUICK_CONFIRMATION_STATUSES,
} from '@/lib/order-status';
import { formatMoroccoPhoneForWhatsApp } from '@/lib/phone';
import { cn } from '@/lib/utils';
import type { Order, OrderConfirmationStatus } from '@/types';
import { Kbd } from '../ui/kbd';

const QUICK_STATUS_SHORTCUTS: Record<string, OrderConfirmationStatus> = {
    '1': QUICK_CONFIRMATION_STATUSES[0],
    '2': QUICK_CONFIRMATION_STATUSES[1],
    '3': QUICK_CONFIRMATION_STATUSES[2],
};

/**
 * Derived from the map above rather than written out twice, so a printed key
 * can never advertise a shortcut that doesn't fire it — reordering
 * QUICK_CONFIRMATION_STATUSES used to silently desync the two. The three
 * quick statuses no longer have dedicated buttons, so the popover row is the
 * only place these keys are discoverable.
 */
const QUICK_SHORTCUT_BY_STATUS = Object.fromEntries(
    Object.entries(QUICK_STATUS_SHORTCUTS).map(([key, status]) => [
        status,
        key,
    ]),
) as Partial<Record<OrderConfirmationStatus, string>>;

/**
 * The confirmation agent's stationary right-hand pane (PRD: "the
 * confirmation queue is the product's core daily tool — optimize for
 * speed and minimal round trips"). Every action an agent needs for the
 * selected order lives here, always visible, so processing a lead never
 * requires leaving this one screen — reuses OrderDetail for the read side
 * (identical to what the admin dialog shows) and adds the CTA rail on top.
 */
export function OrderQueuePane({
    order,
    loading,
    error,
    onBack,
    onRetry,
    onStatusChanged,
    onCreateShipment,
    onEdit,
}: {
    order: Order | null;
    loading: boolean;
    /** Set when the detail fetch failed — shown instead of a silent stale/skeleton hang. */
    error?: boolean;
    /** Shown only below the responsive breakpoint, to return to the card list. */
    onBack?: () => void;
    onRetry?: () => void;
    onStatusChanged?: () => void;
    onCreateShipment?: (order: Order) => void;
    /** Not shown once the order has shipped — same edit lock the backend enforces. */
    onEdit?: (order: Order) => void;
}) {
    const [pendingStatus, setPendingStatus] =
        useState<OrderConfirmationStatus | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [blacklistOpen, setBlacklistOpen] = useState(false);
    const [morePopoverOpen, setMorePopoverOpen] = useState(false);
    const [confirmStatus, setConfirmStatus] =
        useState<OrderConfirmationStatus | null>(null);
    const updating = pendingStatus !== null;

    /** Scopes the keyboard shortcuts to this pane — see the listener below. */
    const paneRef = useRef<HTMLDivElement>(null);
    /** Dialled by the `C` shortcut, so it goes through a real anchor. */
    const callLinkRef = useRef<HTMLAnchorElement>(null);

    const digits = order?.customer_phone?.replace(/[^\d+]/g, '');
    const whatsappNumber = order?.customer_phone
        ? formatMoroccoPhoneForWhatsApp(order.customer_phone)
        : null;

    /**
     * `expectedOrderId` guards against the selection moving between the
     * moment an action was triggered and the moment it fires — a keyboard
     * shortcut can be dispatched a frame before the pane catches up, and
     * writing a status to whatever order happens to be selected *now* is
     * exactly the bug that must not happen on an audited transition.
     */
    const applyStatus = (
        value: OrderConfirmationStatus,
        expectedOrderId?: number,
    ) => {
        if (
            !order ||
            (expectedOrderId != null && expectedOrderId !== order.id)
        ) {
            return;
        }

        setPendingStatus(value);
        router.patch(
            updateStatus.url(order.id),
            { confirmation_status: value },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    // Names the order, not just the status — an agent moving
                    // fast has already advanced to the next customer by the
                    // time this lands, so "Confirmed" alone left them unsure
                    // which order it applied to.
                    toast.success(
                        `${confirmationStatusLabels[value]} · ${order.customer_name ?? order.reference ?? `#${order.id}`}`,
                    );
                },
                onError: () => {
                    // States that nothing changed — the badge stays on the
                    // old status, and without saying so the agent can't tell
                    // whether the write half-landed and is left re-pressing
                    // an audited transition.
                    toast.error(
                        `Couldn't save "${confirmationStatusLabels[value]}"`,
                        {
                            description:
                                'The order is unchanged. Check your connection and try again.',
                            action: {
                                label: 'Retry',
                                onClick: () => applyStatus(value, order.id),
                            },
                        },
                    );
                },
                onFinish: () => {
                    setPendingStatus(null);
                    onStatusChanged?.();
                },
            },
        );
    };

    const handleStatusChange = (
        value: OrderConfirmationStatus,
        expectedOrderId?: number,
    ) => {
        if (
            !order ||
            value === order.confirmation_status ||
            (expectedOrderId != null && expectedOrderId !== order.id)
        ) {
            return;
        }

        if (value === 'cancelled') {
            setCancelOpen(true);

            return;
        }

        // `fake` accuses a real person of a prank order and feeds blacklist
        // and stats logic, so it gets the same "are you sure" weight as
        // cancelling rather than applying instantly from the popover.
        if (value === 'fake') {
            setConfirmStatus(value);

            return;
        }

        applyStatus(value);
    };

    // Number-key shortcuts for the 3 dominant outcomes and a Call shortcut —
    // the highest-frequency action in the product (PRD: "optimize for
    // speed and minimal round trips") shouldn't require reaching for the
    // mouse/touch every single time.
    //
    // A bare digit writes an audited, near-irreversible status (UC-7:
    // `confirmed` fires OrderConfirmed, calculates commission, and pushes a
    // parcel to the courier — nothing in this pane recalls that), so the
    // listener is deliberately narrow about when it will fire:
    //
    //  - Only while this pane owns focus. A `window` listener fired from a
    //    focused order card in the list, or from behind an open dialog,
    //    which meant a digit typed anywhere could mutate the order.
    //  - Never from a text-entry context. `tagName` alone missed
    //    contenteditable and role="textbox"; `closest()` also catches a
    //    click landing on an inner element of one.
    //  - Never with a modifier held, so browser/OS chords stay intact.
    //  - Never against a stale order. `selectedId` can change a frame
    //    before the fetched detail catches up, so the id is captured at
    //    keypress and re-checked inside applyStatus.
    useEffect(() => {
        if (!order || updating) {
            return;
        }

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            const target = event.target as HTMLElement | null;
            const root = paneRef.current;

            // `document.body` is the target for keys pressed with nothing
            // focused — that still counts as this pane's, since the pane is
            // the only thing on screen listening.
            const focusIsOutsidePane =
                root &&
                target &&
                target !== document.body &&
                !root.contains(target);

            if (focusIsOutsidePane) {
                return;
            }

            if (
                target?.closest(
                    'input, textarea, select, [contenteditable=""], [contenteditable="true"], [role="textbox"], [role="dialog"], [role="alertdialog"]',
                )
            ) {
                return;
            }

            const shortcutStatus = QUICK_STATUS_SHORTCUTS[event.key];

            if (shortcutStatus) {
                event.preventDefault();
                handleStatusChange(shortcutStatus, order.id);

                return;
            }

            if (event.key.toLowerCase() === 'c' && digits) {
                event.preventDefault();
                // Click the real anchor rather than assigning
                // `window.location.href` — a `tel:` navigation with no
                // registered handler can blank or unload the SPA.
                callLinkRef.current?.click();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [order, updating, digits]);

    if (error && !order) {
        return (
            <Empty className="h-full border-none" role="alert">
                <EmptyTitle>Couldn't load this order</EmptyTitle>
                {/* The button below already says "try again", so this says
                    the one thing it can't: the order itself is fine, only
                    the fetch failed. */}
                <EmptyDescription>
                    The order wasn't changed — this is a connection problem.
                </EmptyDescription>
                {onRetry && (
                    <Button type="button" variant="outline" onClick={onRetry}>
                        <RotateCcw className="size-4" />
                        Retry
                    </Button>
                )}
            </Empty>
        );
    }

    if (loading || !order) {
        return (
            <div
                className="space-y-4 p-6"
                role="status"
                aria-busy="true"
                aria-label="Loading order"
            >
                <Skeleton className="h-8 w-48" />
                <Skeleton className="h-32 w-full" />
                <Skeleton className="h-32 w-full" />
            </div>
        );
    }

    return (
        <div ref={paneRef} className="flex h-full flex-col">
            {/* The agent's job is "call this person", so the person — not the
                order reference — is the pane's title. The number sits right
                under it in tabular figures, large enough to read aloud off
                the screen while dialling a desk handset, which the icon-only
                tel: button alone never allowed. */}
            <div className="flex items-start gap-2 border-b p-4">
                {onBack && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-11 shrink-0 lg:size-8"
                        aria-label="Back to queue"
                        onClick={onBack}
                    >
                        <ChevronLeft />
                    </Button>
                )}
                <div className="min-w-0 flex-1">
                    <h2
                        className="truncate text-lg leading-tight font-semibold"
                        title={order.customer_name ?? undefined}
                    >
                        {order.customer_name ?? 'Unnamed customer'}
                    </h2>
                    <div className="mt-0.5 flex items-center gap-2">
                        {order.customer_phone ? (
                            <span className="truncate font-mono text-sm text-muted-foreground tabular-nums">
                                {order.customer_phone}
                            </span>
                        ) : (
                            <span className="text-sm text-muted-foreground">
                                No phone on file
                            </span>
                        )}
                        <span aria-hidden className="text-muted-foreground/40">
                            ·
                        </span>
                        <span
                            className="truncate font-mono text-xs text-muted-foreground"
                            title={order.reference ?? undefined}
                        >
                            {order.reference ?? `#${order.id}`}
                        </span>
                    </div>
                </div>
            </div>

            <div className="flex-1 overflow-y-auto p-4">
                <OrderDetail
                    order={order}
                    variant="queue"
                    showHistory={false}
                    onCreateShipment={onCreateShipment}
                />
            </div>

            <div className="space-y-2 border-t p-4">
                {/* Two rows, not one: opening a channel (call, WhatsApp)
                    changes nothing, while recording an outcome writes an
                    audited transition that counts toward auto-cancel.
                    Rendering them as one undifferentiated grid made a
                    one-column slip flip a lead toward cancellation. */}
                <div className="grid grid-cols-2 gap-2">
                    {/* Tinted rather than filled: WhatsApp earns a solid fill
                        because it carries a real brand, and a second saturated
                        block beside it would both fight that mark and outweigh
                        the primary CTA below. The theme's own --info blue
                        marks this as the other channel action without
                        inventing a colour for the telephone. */}
                    {digits ? (
                        <Button
                            asChild
                            variant="outline"
                            className="h-11 border-info/30 bg-info/10 text-info-text hover:bg-info/15 hover:text-info-text dark:bg-info/15 dark:hover:bg-info/20"
                        >
                            <a ref={callLinkRef} href={`tel:${digits}`}>
                                <Phone className="size-4" />
                                Call
                                <Kbd aria-hidden>C</Kbd>
                            </a>
                        </Button>
                    ) : (
                        // A disabled <a> is not a thing — `disabled` on an
                        // anchor is dropped, leaving a focusable control that
                        // looks enabled and silently does nothing. Render a
                        // real disabled button instead.
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            disabled
                        >
                            <Phone className="size-4" />
                            Call
                        </Button>
                    )}
                    {/* Carries WhatsApp's own green and mark: this opens an
                        external app, and an agent scanning the rail mid-call
                        finds it by colour before reading either label.

                        Ink is near-black, not white — measured, the brand
                        #25D366 against white is 1.98:1, nowhere near the 4.5:1
                        a label needs, while the same green under dark ink is
                        10.59:1. The alternative (WhatsApp's darker #128C7E
                        with white text) only reaches 4.14:1 and gives up the
                        recognisable colour to get there. */}
                    {whatsappNumber ? (
                        <Button
                            asChild
                            className="h-11 bg-[#25D366] text-neutral-950 hover:bg-[#1EBE5A] focus-visible:ring-[#25D366]/40"
                        >
                            <a
                                href={`https://wa.me/${whatsappNumber}`}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <WhatsAppIcon />
                                WhatsApp
                            </a>
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            disabled
                        >
                            <WhatsAppIcon />
                            WhatsApp
                        </Button>
                    )}
                </div>

                {/* The rail's primary action. The current status is already
                    on the order card and in the detail above, so this control
                    names what pressing it does instead of restating state.

                    The overflow menu beside it holds the actions that are not
                    part of the call itself — editing the order, copying the
                    number, opening the customer. Blacklisting deliberately is
                    not here: it needs `manage-users` (admin only), and this
                    pane renders only for confirmation agents, so the button
                    would 403 for every user who can see it. */}
                <div className="flex gap-2">
                    <Popover
                        open={morePopoverOpen}
                        onOpenChange={setMorePopoverOpen}
                    >
                        <PopoverTrigger asChild>
                            <Button
                                type="button"
                                className="h-12 flex-1 text-base"
                                disabled={updating}
                                aria-label={`Change status. Currently ${confirmationStatusLabels[order.confirmation_status]}`}
                            >
                                {updating && pendingStatus ? (
                                    <>
                                        <Loader2 className="animate-spin" />
                                        Saving{' '}
                                        {
                                            confirmationStatusLabels[
                                                pendingStatus
                                            ]
                                        }
                                        …
                                    </>
                                ) : (
                                    <>
                                        Change status
                                        <ChevronDown aria-hidden />
                                    </>
                                )}
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent
                            className="w-(--radix-popover-trigger-width) p-0"
                            align="start"
                        >
                            <Command>
                                <CommandList>
                                    {/* Every status, not just the long tail —
                                    this popover is now the only path to any
                                    of them, so filtering out the former
                                    quick-button three would strand them. */}
                                    {CONFIRMATION_STATUS_GROUPS.map((group) => (
                                        <CommandGroup
                                            key={group.label}
                                            heading={group.label}
                                        >
                                            {group.statuses.map((status) => {
                                                const isCurrent =
                                                    order.confirmation_status ===
                                                    status;

                                                return (
                                                    <CommandItem
                                                        key={status}
                                                        value={status}
                                                        // The check alone was easy
                                                        // to miss in a list of
                                                        // eleven; the row the
                                                        // order is already on
                                                        // should be findable
                                                        // without hunting for a
                                                        // tick at the far edge.
                                                        className={cn(
                                                            isCurrent &&
                                                                'bg-accent/60',
                                                        )}
                                                        onSelect={() => {
                                                            handleStatusChange(
                                                                status,
                                                            );
                                                            setMorePopoverOpen(
                                                                false,
                                                            );
                                                        }}
                                                    >
                                                        {/* Hue matches the badge
                                                        this row produces, so
                                                        picking a status and
                                                        reading it back agree
                                                        on color. Decorative:
                                                        the label carries the
                                                        meaning on its own. */}
                                                        <span
                                                            aria-hidden
                                                            className={cn(
                                                                'size-1.5 shrink-0 rounded-full',
                                                                confirmationStatusDotColors[
                                                                    status
                                                                ],
                                                            )}
                                                        />
                                                        <span
                                                            className={cn(
                                                                'truncate',
                                                                isCurrent &&
                                                                    'font-medium',
                                                            )}
                                                        >
                                                            {
                                                                confirmationStatusLabels[
                                                                    status
                                                                ]
                                                            }
                                                        </span>
                                                        <span className="ml-auto flex shrink-0 items-center gap-1.5">
                                                            {QUICK_SHORTCUT_BY_STATUS[
                                                                status
                                                            ] && (
                                                                <Kbd
                                                                    aria-hidden
                                                                >
                                                                    {
                                                                        QUICK_SHORTCUT_BY_STATUS[
                                                                            status
                                                                        ]
                                                                    }
                                                                </Kbd>
                                                            )}
                                                            <Check
                                                                className={cn(
                                                                    'size-4',
                                                                    !isCurrent &&
                                                                        'opacity-0',
                                                                )}
                                                            />
                                                        </span>
                                                    </CommandItem>
                                                );
                                            })}
                                        </CommandGroup>
                                    ))}
                                </CommandList>
                            </Command>
                        </PopoverContent>
                    </Popover>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="outline"
                                className="h-12 w-12 shrink-0"
                                aria-label="More actions"
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            {onEdit && order.delivery_status === null && (
                                <DropdownMenuItem
                                    onSelect={() => onEdit(order)}
                                >
                                    <Pencil />
                                    Edit order
                                </DropdownMenuItem>
                            )}

                            {order.customer_phone && (
                                <DropdownMenuItem
                                    onSelect={() => {
                                        navigator.clipboard
                                            ?.writeText(
                                                order.customer_phone ?? '',
                                            )
                                            .then(() =>
                                                toast.success(
                                                    'Phone number copied',
                                                ),
                                            )
                                            .catch(() =>
                                                toast.error(
                                                    "Couldn't copy the number",
                                                ),
                                            );
                                    }}
                                >
                                    <Copy />
                                    Copy phone number
                                </DropdownMenuItem>
                            )}

                            <DropdownMenuSeparator />

                            {/* Cancelling is reachable from the status list too,
                            but it is the one outcome an agent reaches for
                            without thinking of it as a "status" — it belongs
                            where the other order-level actions are. Its own
                            dialog collects the structured reason code. */}
                            <DropdownMenuItem
                                variant="destructive"
                                disabled={
                                    updating ||
                                    order.confirmation_status === 'cancelled'
                                }
                                onSelect={() => setCancelOpen(true)}
                            >
                                <Ban />
                                Cancel order
                            </DropdownMenuItem>

                            {/* Blocks this phone number from ordering again
                                and flags every existing order from it — not
                                just this one. Hidden for test orders, which
                                the controller rejects with a 422 (UC-25):
                                a test order is not a real client, so it
                                cannot be used to blacklist a number. */}
                            {!order.is_test && order.customer_phone && (
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => setBlacklistOpen(true)}
                                >
                                    <ShieldBan />
                                    Blacklist client
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            {/* The status write is otherwise announced only by a toast and a
                silent badge swap, so a screen-reader user pressing `1` got
                no confirmation at all on the most consequential action here. */}
            <output aria-live="polite" className="sr-only">
                {updating && pendingStatus
                    ? `Saving ${confirmationStatusLabels[pendingStatus]}…`
                    : `${confirmationStatusLabels[order.confirmation_status]} — ${order.customer_name ?? 'this order'}`}
            </output>

            {/* Refreshes on close: blacklisting flags the order it was
                opened from, so the pane would otherwise keep showing the
                un-flagged copy it already had. */}
            <BlacklistOrderDialog
                open={blacklistOpen}
                onOpenChange={(open) => {
                    setBlacklistOpen(open);

                    if (!open) {
                        onStatusChanged?.();
                    }
                }}
                order={order}
            />

            <CancelOrderDialog
                open={cancelOpen}
                onOpenChange={(open) => {
                    setCancelOpen(open);

                    if (!open) {
                        onStatusChanged?.();
                    }
                }}
                order={order}
            />

            <AlertDialog
                open={confirmStatus !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirmStatus(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Mark this as a fake order?
                        </AlertDialogTitle>
                        {/* Leads with the consequence that reaches beyond
                            this order — the count against the customer is
                            the part the agent can't undo from here. */}
                        <AlertDialogDescription>
                            This counts against{' '}
                            <span className="font-medium text-foreground">
                                {order.customer_name ?? 'this customer'}
                            </span>
                            {order.customer_phone
                                ? ` (${order.customer_phone})`
                                : ''}{' '}
                            in future duplicate and blacklist checks, which can
                            block their next order. Use it for prank orders, not
                            for a client who changed their mind.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Don't mark it</AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                const value = confirmStatus;
                                setConfirmStatus(null);

                                if (value) {
                                    applyStatus(value, order.id);
                                }
                            }}
                        >
                            Mark fake
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
