import { router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
    ArrowDown,
    ArrowUp,
    Check,
    Copy,
    CopyX,
    Loader2,
    MessageCircle,
    MoreHorizontal,
    Phone,
    ShieldAlert,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    assign,
    updateStatus,
} from '@/actions/App/Http/Controllers/Orders/OrderController';
import { DataTableColumnHeaderServer } from '@/components/data-table/data-table-column-header-server';
import {
    OrderRowActionItems,
    orderRowActions,
} from '@/components/orders/order-row-actions';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime } from '@/lib/format';
import type { Translator } from '@/lib/i18n';
import {
    confirmationStatusColors,
    confirmationStatusLabels,
    deliveryStatusColors,
    deliveryStatusLabels,
    isManualOrderSource,
    MANUALLY_SELECTABLE_CONFIRMATION_STATUSES,
    orderSourceLabel,
    statusChipClass,
} from '@/lib/order-status';
import { formatMoroccoPhoneForWhatsApp } from '@/lib/phone';
import { cn } from '@/lib/utils';
import type { Order, OrderFilters } from '@/types';

export type AgentOption = { id: number; name: string; avatar: string | null };

const UNASSIGNED = '__unassigned__';

/**
 * Persist a confirmation status change immediately (edit-in-place), instead
 * of routing through a separate dialog — confirmation is the highest-touch
 * field in this table, so agents need to update it without leaving the row.
 *
 * Cancelling is the one exception: UC-9 requires a structured reason code,
 * so selecting "Cancelled" opens a dialog (onCancel) instead of persisting
 * immediately like every other status does.
 */
function editConfirmationStatus(
    order: Order,
    value: string,
    onCancel: (order: Order) => void,
    onStart?: () => void,
    onFinish?: () => void,
) {
    if (value === order.confirmation_status) {
        return;
    }

    if (value === 'cancelled') {
        onCancel(order);

        return;
    }

    onStart?.();
    router.patch(
        updateStatus.url(order.id),
        { confirmation_status: value },
        { preserveScroll: true, preserveState: true, onFinish },
    );
}

function ConfirmationStatusPopover({
    order,
    onCancel,
}: {
    order: Order;
    onCancel: (order: Order) => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [updating, setUpdating] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    disabled={updating}
                    className="h-auto p-0 hover:bg-transparent disabled:opacity-70"
                    onClick={(event) => event.stopPropagation()}
                >
                    <Badge
                        variant="outline"
                        className={cn(
                            confirmationStatusColors[order.confirmation_status],
                            statusChipClass,
                            'transition-all',
                        )}
                    >
                        {updating && (
                            <Loader2 className="mr-1 size-3 animate-spin" />
                        )}
                        {t(confirmationStatusLabels[order.confirmation_status])}
                    </Badge>
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-44 rounded-md border-border bg-popover p-1 shadow-md"
                align="start"
                onClick={(event) => event.stopPropagation()}
            >
                {MANUALLY_SELECTABLE_CONFIRMATION_STATUSES.map(
                    ([value, label]) => (
                        <Button
                            key={value}
                            type="button"
                            variant="ghost"
                            onClick={() => {
                                editConfirmationStatus(
                                    order,
                                    value,
                                    onCancel,
                                    () => setUpdating(true),
                                    () => setUpdating(false),
                                );
                                setOpen(false);
                            }}
                            className="h-auto w-full justify-start rounded-sm px-2 py-1.5 text-sm font-normal hover:bg-accent aria-selected:bg-accent"
                            aria-selected={value === order.confirmation_status}
                        >
                            {label}
                        </Button>
                    ),
                )}
            </PopoverContent>
        </Popover>
    );
}

function CopyShippingDetailsButton({ order }: { order: Order }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);
    const copyText =
        `${order.customer_name ?? ''} | Phone: ${order.customer_phone ?? ''} | Address: ${order.customer_address ?? ''}${order.customer_city ? `, ${order.customer_city}` : ''}`.trim();

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-xs"
            onClick={(event) => {
                event.stopPropagation();
                navigator.clipboard.writeText(copyText);
                setCopied(true);
                toast.success('Shipping details copied to clipboard!');
                setTimeout(() => setCopied(false), 2000);
            }}
            title={t('Copy shipping details')}
            aria-label={t('Copy shipping details')}
            className="ml-0.5 size-5 text-muted-foreground hover:text-foreground [&_svg]:size-3.5"
        >
            {copied ? <Check className="text-[#0C7D6F]" /> : <Copy />}
        </Button>
    );
}

/**
 * Click-to-copy for a single mono value (reference, tracking number) — a
 * lighter-weight sibling to CopyShippingDetailsButton's icon-button pattern,
 * since here the value itself is the clickable target rather than a
 * separate action next to it.
 */
function CopyableValue({
    value,
    label,
}: {
    value: string | null;
    label: string;
}) {
    const [copied, setCopied] = useState(false);

    if (!value) {
        return (
            <span className="font-mono text-sm text-muted-foreground">—</span>
        );
    }

    return (
        <Button
            type="button"
            variant="link"
            onClick={(event) => {
                event.stopPropagation();
                navigator.clipboard.writeText(value);
                setCopied(true);
                toast.success(`${label} copied to clipboard!`);
                setTimeout(() => setCopied(false), 2000);
            }}
            title={`Copy ${label.toLowerCase()}`}
            aria-label={`Copy ${label.toLowerCase()}: ${value}`}
            className="group/copy h-auto gap-1.5 p-0 font-mono text-sm font-medium text-foreground no-underline hover:no-underline"
        >
            <span>{value}</span>
            {copied ? (
                <Check className="size-3.5 shrink-0 text-[#0C7D6F]" />
            ) : (
                <Copy className="size-3.5 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover/copy:opacity-100" />
            )}
        </Button>
    );
}

/**
 * Up or down arrow beside the agent when their edits moved the order's
 * items total; the amount is in the tooltip. Nothing when untouched.
 */
function UpsellArrow({ amount }: { amount: string | null }) {
    const { t } = useTranslation();
    const value = amount === null ? 0 : parseFloat(amount);

    if (!value) {
        return null;
    }

    const up = value > 0;
    const Icon = up ? ArrowUp : ArrowDown;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={cn(
                        'inline-flex items-center',
                        up ? 'text-success' : 'text-destructive',
                    )}
                    aria-label={up ? t('Upsell') : t('Down-sell')}
                >
                    <Icon className="size-3.5" />
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {up ? t('Upsell') : t('Down-sell')}: {up ? '+' : ''}
                {value.toFixed(2)} MAD
            </TooltipContent>
        </Tooltip>
    );
}

function AgentCell({
    order,
    agents,
    getInitials,
}: {
    order: Order;
    agents: AgentOption[];
    getInitials: (name: string) => string;
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
                onFinish: () => setUpdating(false),
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
            <SelectTrigger
                size="sm"
                className="h-auto w-auto gap-2 rounded-md border-none bg-transparent px-1.5 py-1 text-sm shadow-none hover:bg-accent disabled:opacity-70 [&_svg]:opacity-60"
            >
                {updating ? (
                    <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Loader2 className="size-3.5 animate-spin" />
                        <span>{t('Updating…')}</span>
                    </div>
                ) : agent ? (
                    <div className="flex items-center gap-2">
                        <Avatar className="size-7">
                            <AvatarImage
                                src={agent.avatar ?? undefined}
                                alt={agent.name}
                            />
                            <AvatarFallback className="bg-secondary text-[11px] font-semibold text-secondary-foreground">
                                {getInitials(agent.name)}
                            </AvatarFallback>
                        </Avatar>
                        <span className="truncate">{agent.name}</span>
                        <UpsellArrow amount={order.upsell_amount} />
                    </div>
                ) : (
                    <span className="text-muted-foreground">
                        {t('Unassigned')}
                    </span>
                )}
            </SelectTrigger>
            <SelectContent position="popper" align="start">
                <SelectItem value={UNASSIGNED}>{t('Unassigned')}</SelectItem>
                {agents.map((option) => (
                    <SelectItem key={option.id} value={String(option.id)}>
                        <Avatar className="size-5">
                            <AvatarImage
                                src={option.avatar ?? undefined}
                                alt={option.name}
                            />
                            <AvatarFallback className="text-[10px]">
                                {getInitials(option.name)}
                            </AvatarFallback>
                        </Avatar>
                        {option.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export function createColumns({
    t,
    getInitials,
    onView,
    onDelete,
    onCreateShipment,
    onCancel,
    onBlacklist,
    onEdit,
    onShowSamePhone,
    filters,
    routeUrl,
    isAdmin,
    agents,
}: {
    t: Translator;
    getInitials: (name: string) => string;
    onView: (order: Order) => void;
    onDelete: (order: Order) => void;
    onCreateShipment: (order: Order) => void;
    onCancel: (order: Order) => void;
    onBlacklist: (order: Order) => void;
    onEdit: (order: Order) => void;
    /** Lists every order placed from this order's phone number. */
    onShowSamePhone: (order: Order) => void;
    filters: OrderFilters;
    routeUrl: string;
    isAdmin: boolean;
    agents: AgentOption[];
}): ColumnDef<Order>[] {
    const sortHeader = (title: string, sortKey: string) => (
        <DataTableColumnHeaderServer
            title={title}
            sortKey={sortKey}
            currentSort={filters.sort}
            currentDirection={filters.direction}
            routeUrl={routeUrl}
            query={filters}
        />
    );

    const agentColumn: ColumnDef<Order> = {
        id: 'agent',
        accessorFn: (order) => order.assigned_agent?.name ?? '—',
        header: t('Agent'),
        cell: ({ row }) => (
            <AgentCell
                order={row.original}
                agents={agents}
                getInitials={getInitials}
            />
        ),
    };

    const selectColumn: ColumnDef<Order> = {
        id: 'select',
        header: ({ table }) => (
            <Checkbox
                checked={
                    table.getIsAllPageRowsSelected() ||
                    (table.getIsSomePageRowsSelected() && 'indeterminate')
                }
                onCheckedChange={(value) =>
                    table.toggleAllPageRowsSelected(!!value)
                }
                aria-label={t('Select all')}
            />
        ),
        cell: ({ row }) => (
            <Checkbox
                checked={row.getIsSelected()}
                onCheckedChange={(value) => row.toggleSelected(!!value)}
                onClick={(event) => event.stopPropagation()}
                aria-label={t('Select row')}
            />
        ),
        enableSorting: false,
        enableHiding: false,
    };

    return [
        selectColumn,
        {
            accessorKey: 'reference',
            header: () => sortHeader(t('Ref'), 'reference'),
            cell: ({ row }) => (
                <div className="flex items-center gap-1.5">
                    <CopyableValue
                        value={row.original.reference}
                        label="Reference"
                    />
                    {row.original.is_test && (
                        <Badge variant="secondary" className="shrink-0">
                            {t('Test')}
                        </Badge>
                    )}
                </div>
            ),
        },
        {
            accessorKey: 'ordered_at',
            header: () => sortHeader(t('Ordered'), 'ordered_at'),
            cell: ({ row }) =>
                formatDateTime(
                    row.original.ordered_at ?? row.original.created_at,
                ),
        },
        ...(isAdmin ? [agentColumn] : []),
        {
            id: 'customer',
            accessorFn: (order) => order.customer_name ?? '',
            header: t('Customer'),
            cell: ({ row }) => {
                const order = row.original;
                const { customer_name, customer_phone } = order;
                const digits = customer_phone?.replace(/[^\d+]/g, '');
                const whatsappNumber = customer_phone
                    ? formatMoroccoPhoneForWhatsApp(customer_phone)
                    : null;

                return (
                    <div className="grid gap-0.5">
                        <div className="flex items-center gap-1.5">
                            <span className="font-medium whitespace-nowrap">
                                {customer_name}
                            </span>
                            <CopyShippingDetailsButton order={order} />
                            {order.is_duplicate_flagged && (
                                <Badge
                                    asChild
                                    variant="outline"
                                    className="shrink-0 cursor-pointer rounded-full border-transparent bg-[#EFA22C]/12 px-2 font-semibold text-[#A87110] hover:bg-[#EFA22C]/20"
                                >
                                    <button
                                        type="button"
                                        title={t(
                                            'Show all orders from this phone number',
                                        )}
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            onShowSamePhone(order);
                                        }}
                                    >
                                        <CopyX />
                                        {t('Duplicate')}
                                    </button>
                                </Badge>
                            )}
                            {order.is_blacklist_flagged && (
                                <Badge
                                    variant="destructive"
                                    className="shrink-0 rounded-full border-transparent bg-[#D92D20]/10 px-2 font-semibold text-[#B3281D]"
                                    title={t(
                                        'Customer phone number is on the blacklist',
                                    )}
                                >
                                    <ShieldAlert />
                                    {t('Blacklisted')}
                                </Badge>
                            )}
                        </div>
                        {digits ? (
                            <Popover>
                                <PopoverTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="link"
                                        onClick={(event) =>
                                            event.stopPropagation()
                                        }
                                        className="h-auto w-fit p-0 font-mono text-xs text-muted-foreground no-underline hover:text-foreground"
                                    >
                                        {customer_phone}
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent
                                    className="w-44 rounded-md border-border bg-popover p-1 shadow-md"
                                    onClick={(event) => event.stopPropagation()}
                                >
                                    <Button
                                        asChild
                                        variant="ghost"
                                        className="h-auto w-full justify-start gap-2 rounded-sm px-2 py-1.5 text-sm font-normal text-[#2E7389] hover:bg-accent hover:text-[#2E7389]"
                                    >
                                        <a href={`tel:${digits}`}>
                                            <Phone />
                                            {t('Call')}
                                        </a>
                                    </Button>
                                    {whatsappNumber && (
                                        <Button
                                            asChild
                                            variant="ghost"
                                            className="h-auto w-full justify-start gap-2 rounded-sm px-2 py-1.5 text-sm font-normal text-[#0C7D6F] hover:bg-accent hover:text-[#0C7D6F]"
                                        >
                                            <a
                                                href={`https://wa.me/${whatsappNumber}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <MessageCircle />
                                                {t('WhatsApp')}
                                            </a>
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => {
                                            navigator.clipboard.writeText(
                                                customer_phone ?? '',
                                            );
                                            toast.success(
                                                'Phone number copied to clipboard!',
                                            );
                                        }}
                                        className="h-auto w-full justify-start gap-2 rounded-sm px-2 py-1.5 text-sm font-normal text-muted-foreground hover:bg-accent"
                                    >
                                        <Copy />
                                        {t('Copy')}
                                    </Button>
                                </PopoverContent>
                            </Popover>
                        ) : (
                            <span className="font-mono text-xs text-muted-foreground">
                                {customer_phone}
                            </span>
                        )}
                    </div>
                );
            },
        },
        {
            accessorKey: 'total_amount',
            header: () => sortHeader(t('Price'), 'total_amount'),
            cell: ({ row }) => (
                <span className="font-mono text-sm font-semibold whitespace-nowrap tabular-nums">
                    {row.original.total_amount}
                </span>
            ),
        },
        {
            accessorKey: 'source_platform',
            header: () => sortHeader(t('Source'), 'source_platform'),
            cell: ({ row }) => {
                const order = row.original;

                // A synced order is identified by its store first — that is
                // what an admin scans for — with the platform underneath as
                // the quieter qualifier. A manual order has no store, so it
                // shows how the client reached the business instead.
                if (isManualOrderSource(order.source_platform)) {
                    return (
                        <Badge variant="outline" className="font-normal">
                            {t(orderSourceLabel(order.source_platform))}
                        </Badge>
                    );
                }

                return (
                    <div className="grid gap-0.5">
                        <span className="truncate text-sm font-medium whitespace-nowrap">
                            {order.store?.name ??
                                t(orderSourceLabel(order.source_platform))}
                        </span>
                        <span className="truncate text-xs text-muted-foreground">
                            {order.store?.platform?.name ??
                                t(orderSourceLabel(order.source_platform))}
                        </span>
                    </div>
                );
            },
        },
        {
            accessorKey: 'confirmation_status',
            header: () => sortHeader(t('Confirmation'), 'confirmation_status'),
            cell: ({ row }) => {
                const order = row.original;

                return (
                    <ConfirmationStatusPopover
                        order={order}
                        onCancel={onCancel}
                    />
                );
            },
        },
        {
            accessorKey: 'delivery_status',
            header: () => sortHeader(t('Delivery'), 'delivery_status'),
            cell: ({ row }) => {
                const order = row.original;
                const status = order.delivery_status;

                if (status) {
                    return (
                        <Badge
                            variant="outline"
                            className={cn(
                                deliveryStatusColors[status],
                                statusChipClass,
                            )}
                        >
                            {t(deliveryStatusLabels[status])}
                        </Badge>
                    );
                }

                if (order.confirmation_status === 'confirmed') {
                    return (
                        <Button
                            type="button"
                            variant="link"
                            onClick={(event) => {
                                event.stopPropagation();
                                onCreateShipment(order);
                            }}
                            className="h-8 gap-2 rounded-md border border-input bg-card px-2.5 text-xs font-medium text-foreground no-underline shadow-xs hover:bg-accent hover:text-accent-foreground hover:no-underline [&_svg]:size-4"
                        >
                            <Truck />
                            {t('Create shipment')}
                        </Button>
                    );
                }

                return '—';
            },
        },
        {
            id: 'actions',
            enableSorting: false,
            enableHiding: false,
            cell: ({ row }) => {
                const order = row.original;
                const actions = orderRowActions({
                    order,
                    isAdmin,
                    onView,
                    onDelete,
                    onBlacklist,
                    onEdit,
                });

                return (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                                aria-label={t('Order actions')}
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            className="w-44 rounded-md border-border bg-popover p-1 shadow-md"
                        >
                            <OrderRowActionItems
                                actions={actions}
                                Item={DropdownMenuItem}
                            />
                        </DropdownMenuContent>
                    </DropdownMenu>
                );
            },
        },
    ];
}
