import { router } from '@inertiajs/react';
import {
    AlertCircle,
    Copy,
    NotebookText,
    Phone,
    Truck,
    User,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { assign } from '@/actions/App/Http/Controllers/Orders/OrderController';
import { WhatsAppIcon } from '@/components/icons/whatsapp-icon';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemDescription,
    ItemGroup,
    ItemMedia,
    ItemSeparator,
    ItemTitle,
} from '@/components/ui/item';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format';
import {
    cancellationReasonLabels,
    confirmationStatusColors,
    confirmationStatusLabels,
    deliveryStatusColors,
    deliveryStatusLabels,
    orderSourceLabel,
    returnReasonLabels,
    statusEventLabel,
} from '@/lib/order-status';
import { formatMoroccoPhoneForWhatsApp } from '@/lib/phone';
import { cn } from '@/lib/utils';
import type { AgentOption } from '@/pages/orders/columns';
import type { Order } from '@/types';

const UNASSIGNED = '__unassigned__';

/**
 * The one number an agent or admin scans for first — given real
 * typographic weight instead of sitting at the same size as "Delivery
 * cost" in a field grid, per PRD's speed-first framing of this screen.
 */
function TotalAmount({ value }: { value: string }) {
    const { t } = useTranslation();

    return (
        <div className="flex items-baseline justify-between rounded-lg border bg-muted/30 px-4 py-3">
            <span className="text-sm font-medium text-muted-foreground">
                {t('Total')}
            </span>
            <span className="text-2xl font-semibold tracking-tight tabular-nums">
                {Number(value).toFixed(2)}{' '}
                <span className="text-sm font-normal text-muted-foreground">
                    MAD
                </span>
            </span>
        </div>
    );
}

/**
 * Copy-to-clipboard for a single value, with an inline confirmation flash
 * — the same "copied" pattern used across the orders table
 * (CopyShippingDetailsButton), reused here instead of duplicated.
 */
function CopyButton({ value, label }: { value: string; label: string }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`Copy ${label.toLowerCase()}`}
            onClick={() => {
                navigator.clipboard.writeText(value);
                setCopied(true);
                toast.success(t(':label copied to clipboard!', { label }));
                setTimeout(() => setCopied(false), 2000);
            }}
        >
            <Copy className={copied ? 'text-emerald-500' : undefined} />
        </Button>
    );
}

/**
 * The confirmation agent shown as an assignable field rather than plain
 * text — an unassigned order is a stuck order (PRD principle: "never
 * silently lose or strand an order"), so admins get a one-click path to
 * fix it right where they're already looking, instead of a trip back to
 * the datatable's agent column.
 */
function AssignedAgent({
    order,
    agents,
    onAssigned,
}: {
    order: Order;
    agents: AgentOption[];
    onAssigned?: () => void;
}) {
    const { t } = useTranslation();

    const [updating, setUpdating] = useState(false);
    const agent = order.assigned_agent;

    const handleAssign = (value: string) => {
        const agentId = value === UNASSIGNED ? null : Number(value);

        if (agentId === (order.assigned_agent_id ?? null)) {
            return;
        }

        setUpdating(true);
        router.patch(
            assign.url(order.id),
            { assigned_agent_id: agentId },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    setUpdating(false);
                    onAssigned?.();
                },
            },
        );
    };

    return (
        <Select
            value={
                order.assigned_agent_id
                    ? String(order.assigned_agent_id)
                    : UNASSIGNED
            }
            onValueChange={handleAssign}
            disabled={updating}
        >
            {/* `!h-auto` is deliberate: SelectTrigger pins itself to 32px via
                `data-[size=default]:h-8`, which outranks a plain `h-auto` on
                specificity. Without the override the trigger stays 32px tall
                while the two-line row inside renders ~56px, and the avatar and
                description spill straight through the border.

                `min-w-0` on the text column, and `truncate` on both lines, so
                a long agent name shortens instead of shoving the chevron out
                of the card. */}
            <SelectTrigger
                disabled={updating}
                className={cn(
                    '!h-auto w-full justify-start gap-3 rounded-lg px-3 py-2.5 text-left',
                    agent
                        ? 'border-input'
                        : 'border-dashed bg-transparent hover:bg-muted/50',
                )}
            >
                {agent ? (
                    <>
                        <Avatar className="size-8 shrink-0 rounded-sm">
                            <AvatarImage
                                src={agent.avatar ?? undefined}
                                alt={agent.name}
                            />
                            <AvatarFallback className="rounded-sm text-xs">
                                {agent.name.slice(0, 2).toUpperCase()}
                            </AvatarFallback>
                        </Avatar>
                        <span className="grid min-w-0 flex-1 gap-0.5">
                            <span className="truncate text-sm font-medium">
                                {agent.name}
                            </span>
                            <span className="truncate text-xs font-normal text-muted-foreground">
                                {t('Confirmation agent')}
                            </span>
                        </span>
                    </>
                ) : (
                    <>
                        <User className="size-4 shrink-0 text-muted-foreground" />
                        <span className="grid min-w-0 flex-1 gap-0.5">
                            <span className="truncate text-sm font-medium">
                                {t('Unassigned')}
                            </span>
                            <span className="truncate text-xs font-normal text-muted-foreground">
                                {t('Click to assign an agent')}
                            </span>
                        </span>
                    </>
                )}
            </SelectTrigger>
            <SelectContent position="popper" align="start">
                <SelectItem value={UNASSIGNED}>{t('Unassigned')}</SelectItem>
                {agents.map((option) => (
                    <SelectItem key={option.id} value={String(option.id)}>
                        {option.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * Full order detail, shared between the admin's large view dialog and the
 * confirmation agent's call-center detail pane — the two must never show
 * different information for the same order, so both render this instead of
 * maintaining parallel layouts. `order` should come from OrderController's
 * show() endpoint (or an equally complete payload) so status_events is
 * populated; a list-row order still renders fine, just without history.
 *
 * Every section renders in one flat scroll (no tabs) — an agent moving
 * fast through a queue shouldn't have to click into a tab to see items.
 */
export function OrderDetail({
    order,
    onCreateShipment,
    showHistory = true,
    agents,
    onAgentAssigned,
    variant = 'admin',
}: {
    order: Order;
    onCreateShipment?: (order: Order) => void;
    /** The agent's queue pane skips history — it's the admin's concern. */
    showHistory?: boolean;
    /** Passing this (admin-only) turns the confirmation agent field into an assign control. */
    agents?: AgentOption[];
    onAgentAssigned?: () => void;
    /**
     * Same facts either way — only emphasis differs. The `queue` variant
     * drops the contact buttons the pane's own action rail already owns at a
     * full 44px, so the same two actions don't appear twice at two sizes.
     */
    variant?: 'admin' | 'queue';
}) {
    const { t } = useTranslation();

    const isQueue = variant === 'queue';
    const digits = order.customer_phone?.replace(/[^\d+]/g, '');
    const whatsappNumber = order.customer_phone
        ? formatMoroccoPhoneForWhatsApp(order.customer_phone)
        : null;
    const items = order.items ?? [];
    const events = [...(order.status_events ?? [])].reverse();

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <Badge
                    key={order.confirmation_status}
                    variant="outline"
                    className={cn(
                        confirmationStatusColors[order.confirmation_status],
                        'animate-in duration-300 zoom-in-95 fade-in',
                    )}
                >
                    {t(confirmationStatusLabels[order.confirmation_status])}
                </Badge>
                {order.delivery_status && (
                    <Badge
                        key={order.delivery_status}
                        variant="outline"
                        className={cn(
                            deliveryStatusColors[order.delivery_status],
                            'animate-in duration-300 zoom-in-95 fade-in',
                        )}
                    >
                        {t(deliveryStatusLabels[order.delivery_status])}
                    </Badge>
                )}
                {order.is_test && (
                    <Badge variant="secondary">{t('Test')}</Badge>
                )}
                {order.is_duplicate_flagged && (
                    <Badge
                        variant="outline"
                        className="border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400"
                    >
                        Duplicate
                    </Badge>
                )}
                {order.is_blacklist_flagged && (
                    <Badge variant="destructive">{t('Blacklisted')}</Badge>
                )}

                {/* Where the order came from — a WhatsApp lead and a Shopify
                    order open the call differently. */}
                <Badge variant="outline" className="font-normal">
                    {t(orderSourceLabel(order.source_platform))}
                </Badge>

                {order.store?.name && (
                    <span className="ml-auto flex items-center gap-2 text-sm text-muted-foreground">
                        <span>{order.store.name}</span>
                        {/* The platform name is dropped when the source badge
                            already carries it — for a synced order the two are
                            the same string, and printing it twice on one line
                            reads as a bug. */}
                        {order.store.platform?.name &&
                            order.store.platform.name !==
                                t(orderSourceLabel(order.source_platform)) && (
                                <>
                                    <span aria-hidden>·</span>
                                    <span>{order.store.platform.name}</span>
                                </>
                            )}
                    </span>
                )}
            </div>

            {order.notes && (
                <Alert variant="warning">
                    <NotebookText />
                    <AlertTitle>{t('Notes')}</AlertTitle>
                    <AlertDescription className="whitespace-pre-wrap">
                        {order.notes}
                    </AlertDescription>
                </Alert>
            )}

            <TotalAmount value={order.total_amount} />

            <ItemGroup>
                <Item variant="outline">
                    <ItemMedia variant="icon">
                        <User />
                    </ItemMedia>
                    <ItemContent>
                        <ItemTitle>{order.customer_name}</ItemTitle>
                        {order.customer_phone && (
                            <ItemDescription>
                                {order.customer_phone}
                            </ItemDescription>
                        )}
                        <ItemDescription>
                            {[order.customer_address, order.customer_city]
                                .filter(Boolean)
                                .join(', ') || t('No address on file')}
                        </ItemDescription>
                    </ItemContent>
                    {order.customer_phone && (
                        <ItemActions>
                            {/* The queue pane's action rail already carries
                                Call and WhatsApp at 44px; repeating them here
                                at 28px gave the same two actions two sizes on
                                one screen. Copy stays — the rail has no
                                equivalent. */}
                            {!isQueue && (
                                <>
                                    <Button
                                        asChild
                                        variant="ghost"
                                        size="icon-sm"
                                    >
                                        <a
                                            href={`tel:${digits}`}
                                            aria-label={t('Call customer')}
                                        >
                                            <Phone className="text-blue-600 dark:text-blue-400" />
                                        </a>
                                    </Button>
                                    {whatsappNumber && (
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="icon-sm"
                                        >
                                            <a
                                                href={`https://wa.me/${whatsappNumber}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                aria-label={t(
                                                    'Message on WhatsApp',
                                                )}
                                            >
                                                <WhatsAppIcon className="text-[#25D366]" />
                                            </a>
                                        </Button>
                                    )}
                                </>
                            )}
                            <CopyButton
                                value={order.customer_phone}
                                label={t('Phone number')}
                            />
                        </ItemActions>
                    )}
                </Item>

                {agents && (
                    <AssignedAgent
                        order={order}
                        agents={agents}
                        onAssigned={onAgentAssigned}
                    />
                )}
            </ItemGroup>

            {items.length > 0 && (
                <Card size="sm">
                    <CardContent className="p-3">
                        <ItemGroup>
                            {items.map((item, index) => (
                                <div key={item.id}>
                                    {index > 0 && <ItemSeparator />}
                                    <Item size="sm">
                                        <ItemMedia variant="image">
                                            <Avatar className="size-10 rounded-md">
                                                <AvatarImage
                                                    src={
                                                        item.product
                                                            ?.thumbnail ??
                                                        undefined
                                                    }
                                                    alt={
                                                        item.product_name_snapshot
                                                    }
                                                    className="object-cover"
                                                />
                                                <AvatarFallback className="rounded-md text-xs">
                                                    {item.product_name_snapshot
                                                        .slice(0, 2)
                                                        .toUpperCase()}
                                                </AvatarFallback>
                                            </Avatar>
                                        </ItemMedia>
                                        <ItemContent>
                                            <ItemTitle>
                                                {item.product?.public_url ? (
                                                    <a
                                                        href={
                                                            item.product
                                                                .public_url
                                                        }
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="hover:underline"
                                                    >
                                                        {
                                                            item.product_name_snapshot
                                                        }
                                                    </a>
                                                ) : (
                                                    item.product_name_snapshot
                                                )}
                                            </ItemTitle>
                                            <ItemDescription>
                                                {t('Qty :quantity', {
                                                    quantity: item.quantity,
                                                })}
                                                {item.sku_snapshot &&
                                                    ` · ${t('SKU')} ${item.sku_snapshot}`}
                                            </ItemDescription>
                                        </ItemContent>
                                        <ItemActions>
                                            <span className="text-sm font-semibold">
                                                {item.unit_price}
                                            </span>
                                        </ItemActions>
                                    </Item>
                                </div>
                            ))}
                        </ItemGroup>
                    </CardContent>
                </Card>
            )}

            {(order.delivery_account || order.courier_tracking_number) && (
                <ItemGroup>
                    <Item variant="outline">
                        <ItemMedia variant="icon">
                            <Truck />
                        </ItemMedia>
                        <ItemContent>
                            <ItemTitle>
                                {order.delivery_account?.courier?.name ??
                                    t('Delivery')}
                            </ItemTitle>
                            <ItemDescription>
                                {order.delivery_driver_name}
                                {order.delivery_driver_name &&
                                    order.shipped_at &&
                                    ' · '}
                                {order.shipped_at &&
                                    `Shipped ${formatDateTime(order.shipped_at)}`}
                            </ItemDescription>
                        </ItemContent>
                        {order.courier_tracking_number && (
                            <ItemActions>
                                <span className="font-mono text-sm">
                                    {order.courier_tracking_number}
                                </span>
                                <CopyButton
                                    value={order.courier_tracking_number}
                                    label={t('Tracking number')}
                                />
                            </ItemActions>
                        )}
                    </Item>
                </ItemGroup>
            )}

            {!order.delivery_account &&
                !order.courier_tracking_number &&
                order.confirmation_status === 'confirmed' &&
                onCreateShipment && (
                    <Empty className="border border-dashed py-8">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Truck />
                            </EmptyMedia>
                            <EmptyTitle>{t('Not shipped yet')}</EmptyTitle>
                            <EmptyDescription>
                                {t(
                                    'This order is confirmed but has no parcel with a courier.',
                                )}
                            </EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => onCreateShipment(order)}
                            >
                                <Truck className="size-3.5" />
                                {t('Create shipment')}
                            </Button>
                        </EmptyContent>
                    </Empty>
                )}

            {(order.cancellation_reason_code || order.return_reason_code) && (
                <Alert variant="destructive">
                    <AlertCircle />
                    <AlertTitle>
                        {order.cancellation_reason_code
                            ? t('Cancellation reason')
                            : t('Return reason')}
                    </AlertTitle>
                    <AlertDescription>
                        {order.cancellation_reason_code
                            ? cancellationReasonLabels[
                                  order.cancellation_reason_code
                              ]
                            : order.return_reason_code &&
                              t(returnReasonLabels[order.return_reason_code])}
                    </AlertDescription>
                </Alert>
            )}

            {showHistory && (
                <div>
                    <div className="mb-2 text-sm font-medium">
                        {t('History')}
                    </div>
                    {events.length === 0 ? (
                        <p className="py-4 text-center text-sm text-muted-foreground">
                            {t('No status changes recorded yet.')}
                        </p>
                    ) : (
                        <ol className="space-y-4">
                            {events.map((event, index) => {
                                const isCurrent = index === events.length - 1;

                                return (
                                    <li key={event.id} className="flex gap-3">
                                        <div className="flex flex-col items-center">
                                            <span
                                                className={cn(
                                                    'mt-1 shrink-0 rounded-full',
                                                    isCurrent
                                                        ? 'size-2.5 bg-primary ring-4 ring-primary/15'
                                                        : 'size-2 border-2 border-muted-foreground/40 bg-background',
                                                )}
                                            />
                                            {index < events.length - 1 && (
                                                <div className="my-1 w-px flex-1 bg-border" />
                                            )}
                                        </div>
                                        <div className="grid flex-1 gap-0.5 pb-1 leading-tight">
                                            <span
                                                className={cn(
                                                    'text-sm',
                                                    isCurrent
                                                        ? 'font-semibold'
                                                        : 'font-medium text-muted-foreground',
                                                )}
                                            >
                                                {event.from_status
                                                    ? `${t(statusEventLabel(event.from_status))} → ${t(statusEventLabel(event.to_status))}`
                                                    : statusEventLabel(
                                                          event.to_status,
                                                      )}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {event.created_at &&
                                                    formatDateTime(
                                                        event.created_at,
                                                    )}
                                                {event.changed_by_user &&
                                                    ` · ${event.changed_by_user.name}`}
                                            </span>
                                            {event.note && (
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {event.note}
                                                </p>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </div>
            )}
        </div>
    );
}
