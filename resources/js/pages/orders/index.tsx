import { Head, router, usePage } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import type { RowSelectionState } from '@tanstack/react-table';
import {
    CheckCircle2,
    CheckSquare,
    Clock,
    CloudDownload,
    Download,
    Filter,
    PackageCheck,
    Plus,
    Trash2,
    Truck,
    Users,
    X,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DataTable } from '@/components/data-table/data-table';
import {
    DataTableCard,
    DataTableCardFooter,
    DataTableCardTable,
    DataTableCardToolbar,
} from '@/components/data-table/data-table-card';
import { DataTablePaginationServer } from '@/components/data-table/data-table-pagination-server';
import { DataTablePerPageSelect } from '@/components/data-table/data-table-per-page-select';
import { DataTableResetFiltersButton } from '@/components/data-table/data-table-reset-filters-button';
import { DataTableViewOptions } from '@/components/data-table/data-table-view-options';
import Heading from '@/components/heading';
import { BlacklistOrderDialog } from '@/components/orders/blacklist-order-dialog';
import { CancelOrderDialog } from '@/components/orders/cancel-order-dialog';
import { CreateShipmentDialog } from '@/components/orders/create-shipment-dialog';
import { OrderBulkAssignDialog } from '@/components/orders/order-bulk-assign-dialog';
import { OrderBulkDeleteDialog } from '@/components/orders/order-bulk-delete-dialog';
import { OrderBulkStatusDialog } from '@/components/orders/order-bulk-status-dialog';
import { OrderDeleteDialog } from '@/components/orders/order-delete-dialog';
import { OrderFormDialog } from '@/components/orders/order-form-dialog';
import { OrderViewDialog } from '@/components/orders/order-view-dialog';
import { StoreSyncCommand } from '@/components/stores/store-sync-command';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
} from '@/components/ui/collapsible';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiCombobox } from '@/components/ui/multi-combobox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useInitials } from '@/hooks/use-initials';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import type { Translator } from '@/lib/i18n';
import {
    confirmationStatusLabels,
    deliveryStatusLabels,
    orderSourceLabel,
} from '@/lib/order-status';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as ordersIndex, sync as syncOrders } from '@/routes/orders';
import type {
    Order,
    OrderFilters,
    OrderMetrics,
    Paginated,
    PageProps,
} from '@/types';
import type { DeliveryAccount } from '@/types/delivery';
import { createColumns } from './columns';
import type { AgentOption } from './columns';

type SimpleOption = { id: number; name: string };

const NONE = '__none__';

const ADMIN_ROLES = ['admin', 'super_admin'];

const FILTER_KEYS: (keyof OrderFilters)[] = [
    'search',
    'confirmation_status',
    'delivery_status',
    'store_ids',
    'assigned_agent_id',
    'date_from',
    'date_to',
];

function exportOrdersToCsv(orders: Order[], selectedIds: number[], t: Translator) {
    const toExport =
        selectedIds.length > 0
            ? orders.filter((order) => selectedIds.includes(order.id))
            : orders;

    if (toExport.length === 0) {
        return;
    }

    const headers = [
        t('Reference'),
        t('Ordered At'),
        t('Customer Name'),
        t('Phone'),
        t('Address'),
        t('City'),
        t('Total Amount'),
        t('Source'),
        t('Confirmation Status'),
        t('Delivery Status'),
        t('Tracking Number'),
        t('Agent'),
        t('Store'),
    ];

    const rows = toExport.map((order) => [
        `"${order.reference ?? ''}"`,
        `"${order.ordered_at ?? order.created_at ?? ''}"`,
        `"${(order.customer_name ?? '').replace(/"/g, '""')}"`,
        `"${order.customer_phone ?? ''}"`,
        `"${(order.customer_address ?? '').replace(/"/g, '""')}"`,
        `"${(order.customer_city ?? '').replace(/"/g, '""')}"`,
        `"${order.total_amount ?? ''}"`,
        `"${t(orderSourceLabel(order.source_platform))}"`,
        `"${order.confirmation_status ?? ''}"`,
        `"${order.delivery_status ?? ''}"`,
        `"${order.courier_tracking_number ?? ''}"`,
        `"${order.assigned_agent?.name ?? ''}"`,
        `"${order.store?.name ?? ''}"`,
    ]);

    const csvContent =
        'data:text/csv;charset=utf-8,' +
        [headers.join(','), ...rows.map((row) => row.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute(
        'download',
        `orders_export_${new Date().toISOString().slice(0, 10)}.csv`,
    );
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

type OrderMetricField = 'confirmation_status' | 'delivery_status';

const METRIC_CARDS: {
    key: keyof OrderMetrics;
    field: OrderMetricField;
    filterValue: string;
    label: string;
    icon: LucideIcon;
    hoverBorder: string;
    selected: string;
    labelClass: string;
    iconClass: string;
    valueClass: string;
}[] = [
    {
        key: 'new',
        field: 'confirmation_status',
        filterValue: 'new',
        label: 'New',
        icon: Clock,
        hoverBorder: 'hover:border-amber-500/50',
        selected: 'border-amber-500 bg-amber-500/10 shadow-sm ring-1 ring-amber-500',
        labelClass: 'font-medium text-amber-700 dark:text-amber-400',
        iconClass: 'size-4 text-amber-500',
        valueClass: 'text-2xl font-bold',
    },
    {
        key: 'confirmed',
        field: 'confirmation_status',
        filterValue: 'confirmed',
        label: 'Confirmed',
        icon: CheckCircle2,
        hoverBorder: 'hover:border-emerald-500/50',
        selected: 'border-emerald-500 bg-emerald-500/10 shadow-sm ring-1 ring-emerald-500',
        labelClass: 'font-medium text-emerald-700 dark:text-emerald-400',
        iconClass: 'size-4 text-emerald-500',
        valueClass: 'text-2xl font-bold text-emerald-600 dark:text-emerald-500',
    },
    {
        key: 'submitted_to_courier',
        field: 'confirmation_status',
        filterValue: 'submitted_to_courier',
        label: 'Submitted to courier',
        icon: PackageCheck,
        hoverBorder: 'hover:border-violet-500/50',
        selected: 'border-primary bg-primary/10 shadow-sm ring-1 ring-primary',
        labelClass: 'font-medium text-violet-700 dark:text-violet-400',
        iconClass: 'size-4 text-violet-500',
        valueClass: 'text-2xl font-bold text-violet-600 dark:text-violet-500',
    },
    {
        key: 'delivered',
        field: 'delivery_status',
        filterValue: 'delivered',
        label: 'Delivered',
        icon: Truck,
        hoverBorder: 'hover:border-blue-500/50',
        selected: 'border-blue-500 bg-blue-500/10 shadow-sm ring-1 ring-blue-500',
        labelClass: 'font-medium text-blue-700 dark:text-blue-400',
        iconClass: 'size-4 text-blue-500',
        valueClass: 'text-2xl font-bold text-blue-600 dark:text-blue-500',
    },
    {
        key: 'cancelled',
        field: 'confirmation_status',
        filterValue: 'cancelled',
        label: 'Cancelled',
        icon: XCircle,
        hoverBorder: 'hover:border-destructive/50',
        selected: 'border-destructive bg-destructive/10 shadow-sm ring-1 ring-destructive',
        labelClass: 'font-medium text-destructive',
        iconClass: 'size-4 text-destructive',
        valueClass: 'text-2xl font-bold text-destructive',
    },
];

function OrderMetricCard({
    metric,
    count,
    selected,
    onToggle,
}: {
    metric: (typeof METRIC_CARDS)[number];
    count: number;
    selected: boolean;
    onToggle: () => void;
}) {
    const Icon = metric.icon;

    return (
        <Card
            role="button"
            tabIndex={0}
            aria-pressed={selected}
            onClick={onToggle}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onToggle();
                }
            }}
            className={cn(
                'cursor-pointer transition-all hover:shadow-sm focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50',
                metric.hoverBorder,
                selected ? metric.selected : 'py-4 shadow-sm',
            )}
        >
            <CardHeader className="gap-1 px-4 py-3">
                <div className="flex items-center justify-between">
                    <CardDescription className={metric.labelClass}>
                        {t(metric.label)}
                    </CardDescription>
                    <Icon className={metric.iconClass} />
                </div>
                <CardTitle className={metric.valueClass}>{count}</CardTitle>
            </CardHeader>
        </Card>
    );
}

export default function OrdersIndex({
    orders,
    metrics,
    filters,
    stores,
    agents,
    deliveryAccounts,
}: {
    orders: Paginated<Order>;
    metrics: OrderMetrics;
    filters: OrderFilters;
    stores: SimpleOption[];
    agents: AgentOption[];
    deliveryAccounts: DeliveryAccount[];
}) {
    const { t } = useTranslation();

    const [formOpen, setFormOpen] = useState(false);
    const [editOrder, setEditOrder] = useState<Order | null>(null);
    const [viewOrder, setViewOrder] = useState<Order | null>(null);
    const [deleteOrder, setDeleteOrder] = useState<Order | null>(null);
    const [shipmentOrder, setShipmentOrder] = useState<Order | null>(null);
    const [cancelOrder, setCancelOrder] = useState<Order | null>(null);
    const [blacklistOrder, setBlacklistOrder] = useState<Order | null>(null);
    const [loadingOrders, setLoadingOrders] = useState(false);
    const [syncOpen, setSyncOpen] = useState(false);
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
    const [bulkAssignOpen, setBulkAssignOpen] = useState(false);
    const [bulkStatusOpen, setBulkStatusOpen] = useState(false);
    const getInitials = useInitials();
    const { auth } = usePage<PageProps>().props;
    const isAdmin = ADMIN_ROLES.includes(auth.user.role);

    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters(ordersIndex().url, filters, FILTER_KEYS);

    const selectedStores = draft.store_ids ? draft.store_ids.split(',') : [];

    const [filtersOpen, setFiltersOpen] = useState(false);

    const activeFiltersList = useMemo(() => {
        // key is a React list key, not a filter name — multi-select
        // filters emit one chip per value ("store_ids:3"), so it can't be
        // narrowed to keyof OrderFilters.
        const list: { key: string; label: string; onRemove: () => void }[] = [];

        if (draft.confirmation_status && draft.confirmation_status !== NONE) {
            list.push({
                key: 'confirmation_status',
                label: `${t('Status')}: ${t(confirmationStatusLabels[draft.confirmation_status as keyof typeof confirmationStatusLabels]) ?? draft.confirmation_status}`,
                onRemove: () => updateFilters({ confirmation_status: undefined }),
            });
        }

        if (draft.delivery_status && draft.delivery_status !== NONE) {
            list.push({
                key: 'delivery_status',
                label: `${t('Delivery')}: ${t(deliveryStatusLabels[draft.delivery_status as keyof typeof deliveryStatusLabels]) ?? draft.delivery_status}`,
                onRemove: () => updateFilters({ delivery_status: undefined }),
            });
        }

        // One chip per selected store, each removing only itself — a
        // single "Store: 3 selected" chip could only clear the whole
        // filter, forcing a reopen of the picker to drop just one.
        if (draft.store_ids) {
            for (const id of draft.store_ids.split(',')) {
                const storeObj = stores.find((s) => String(s.id) === id);
                list.push({
                    key: `store_ids:${id}`,
                    label: `Store: ${storeObj ? storeObj.name : id}`,
                    onRemove: () => {
                        const next = draft.store_ids
                            ?.split(',')
                            .filter((value) => value !== id);

                        updateFilters({
                            store_ids:
                                next && next.length > 0
                                    ? next.join(',')
                                    : undefined,
                        });
                    },
                });
            }
        }

        if (draft.assigned_agent_id && draft.assigned_agent_id !== NONE) {
            let agentLabel = String(draft.assigned_agent_id);

            if (draft.assigned_agent_id === '__unassigned__') {
                agentLabel = 'Unassigned';
            } else {
                const agentObj = agents.find((a) => String(a.id) === String(draft.assigned_agent_id));

                if (agentObj) {
agentLabel = agentObj.name;
}
            }

            list.push({
                key: 'assigned_agent_id',
                label: `Agent: ${agentLabel}`,
                onRemove: () => updateFilters({ assigned_agent_id: undefined }),
            });
        }

        if (draft.date_from) {
            list.push({
                key: 'date_from',
                label: `From: ${draft.date_from}`,
                onRemove: () => updateFilters({ date_from: undefined }),
            });
        }

        if (draft.date_to) {
            list.push({
                key: 'date_to',
                label: `To: ${draft.date_to}`,
                onRemove: () => updateFilters({ date_to: undefined }),
            });
        }

        return list;
    }, [draft, stores, agents, updateFilters, t]);

    const [search, setSearch] = useState(filters.search ?? '');
    const debouncedSearch = useDebouncedValue(search, 300);
    const isFirstSearchRun = useRef(true);

    useEffect(() => {
        if (isFirstSearchRun.current) {
            isFirstSearchRun.current = false;

            return;
        }

        updateFilters({ search: debouncedSearch || undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    const handleReset = () => {
        setSearch('');
        resetFilters();
    };

    const loadOrders = (storeId: number | null) => {
        setLoadingOrders(true);
        router.post(
            syncOrders().url,
            // `platform: '*'` still means "every platform" — the store
            // picker narrows by store instead, and omitting store_id keeps
            // the original all-stores behaviour.
            { platform: '*', ...(storeId ? { store_id: storeId } : {}) },
            {
                preserveScroll: true,
                onFinish: () => setLoadingOrders(false),
            },
        );
    };

    const columns = useMemo(
        () =>
            createColumns({
                t,
                getInitials,
                onView: setViewOrder,
                onDelete: setDeleteOrder,
                onCreateShipment: setShipmentOrder,
                onCancel: setCancelOrder,
                onBlacklist: setBlacklistOrder,
                onEdit: setEditOrder,
                filters,
                routeUrl: ordersIndex().url,
                isAdmin,
                agents,
            }),
        [getInitials, filters, isAdmin, agents, t],
    );

    const table = useReactTable({
        data: orders.data,
        columns,
        getRowId: (order) => String(order.id),
        enableRowSelection: true,
        onRowSelectionChange: setRowSelection,
        getCoreRowModel: getCoreRowModel(),
        state: { rowSelection },
    });

    const selectedOrderIds = table
        .getFilteredSelectedRowModel()
        .rows.map((row) => row.original.id);

    return (
        <>
            <Head title={t('Orders')} />

            <div className="space-y-6 p-4">
                <Heading
                    title={t('Orders')}
                    description={t('Track and manage your customer orders.')}
                />

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    {METRIC_CARDS.map((metric) => {
                        const selected =
                            draft[metric.field] === metric.filterValue;

                        return (
                            <OrderMetricCard
                                key={metric.key}
                                metric={metric}
                                count={metrics[metric.key]}
                                selected={selected}
                                onToggle={() =>
                                    updateFilters({
                                        [metric.field]: selected
                                            ? undefined
                                            : metric.filterValue,
                                    })
                                }
                            />
                        );
                    })}
                </div>

                <DataTableCard>
                    <DataTableCardToolbar className="gap-4 flex-col sm:flex-row items-stretch sm:items-center">
                        <div className="flex flex-1 flex-wrap items-center gap-2.5">
                            <Label htmlFor="search" className="sr-only">
                                {t('Search orders')}
                            </Label>
                            <Input
                                id="search"
                                className="w-full sm:max-w-xs h-9"
                                placeholder={t('Search reference, tracking number, or exact phone…')}
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setFiltersOpen(!filtersOpen)}
                                className={cn(
                                    'h-9 gap-2 transition-all cursor-pointer',
                                    (filtersOpen || activeFiltersList.length > 0) &&
                                        'border-primary/60 bg-primary/5 font-medium text-primary shadow-2xs',
                                )}
                            >
                                <Filter className="size-4" />
                                <span>{t('Filters')}</span>
                                {activeFiltersList.length > 0 && (
                                    <Badge className="ml-0.5 size-5 flex items-center justify-center rounded-full p-0 text-[11px] font-semibold">
                                        {activeFiltersList.length}
                                    </Badge>
                                )}
                            </Button>
                            {activeFiltersList.length > 0 && (
                                <div className="flex flex-wrap items-center gap-1.5 pt-1 sm:pt-0">
                                    {activeFiltersList.map((item) => (
                                        <Badge
                                            key={item.key}
                                            variant="secondary"
                                            className="h-7 gap-1.5 px-2.5 py-1 text-xs font-normal shadow-2xs hover:bg-secondary/80"
                                        >
                                            <span>{item.label}</span>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-xs"
                                                aria-label={t('Remove filter: :label', { label: item.label })}
                                                onClick={item.onRemove}
                                                className="-mr-1 size-4 text-muted-foreground hover:bg-transparent hover:text-foreground"
                                            >
                                                <X />
                                            </Button>
                                        </Badge>
                                    ))}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={handleReset}
                                        className="h-7 px-2 text-xs text-muted-foreground hover:text-destructive cursor-pointer"
                                    >
                                        Reset all
                                    </Button>
                                </div>
                            )}
                            {selectedOrderIds.length > 0 && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium text-muted-foreground">
                                        {selectedOrderIds.length} selected
                                    </span>
                                    {isAdmin && (
                                        <>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="h-8 gap-1.5 px-2.5"
                                                onClick={() =>
                                                    setBulkAssignOpen(true)
                                                }
                                            >
                                                <Users className="size-3.5" />
                                                <span className="hidden sm:inline">
                                                    {t('Assign Agent')}
                                                </span>
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                className="h-8 gap-1.5 px-2.5"
                                                onClick={() =>
                                                    setBulkDeleteOpen(true)
                                                }
                                            >
                                                <Trash2 className="size-3.5" />
                                                <span className="hidden sm:inline">
                                                    {t('Delete')}
                                                </span>
                                            </Button>
                                        </>
                                    )}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-8 gap-1.5 px-2.5"
                                        onClick={() =>
                                            setBulkStatusOpen(true)
                                        }
                                    >
                                        <CheckSquare className="size-3.5" />
                                        <span className="hidden sm:inline">
                                            {t('Status')}
                                        </span>
                                    </Button>
                                </div>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                className="gap-1.5 px-2.5 sm:px-3"
                                onClick={() =>
                                    exportOrdersToCsv(
                                        orders.data,
                                        selectedOrderIds,
                                        t,
                                    )
                                }
                                title={t('Export current orders to CSV')}
                            >
                                <Download className="size-3.5" />
                                <span className="hidden sm:inline">
                                    {selectedOrderIds.length > 0
                                        ? t('Export selected')
                                        : t('Export CSV')}
                                </span>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="gap-1.5 px-2.5 sm:px-3"
                                onClick={() => setSyncOpen(true)}
                                disabled={loadingOrders}
                                // The label is hidden below `sm`, leaving an
                                // icon-only control with no accessible name.
                                aria-label={t('Load orders')}
                            >
                                <CloudDownload
                                    className={
                                        loadingOrders ? 'animate-spin' : ''
                                    }
                                />
                                <span className="hidden sm:inline">
                                    {loadingOrders
                                        ? t('Loading orders…')
                                        : t('Load orders')}
                                </span>
                            </Button>
                            <Button
                                size="sm"
                                className="px-2.5 sm:px-3"
                                onClick={() => setFormOpen(true)}
                            >
                                <Plus />
                                <span className="hidden sm:inline">
                                    {t('Add order')}
                                </span>
                            </Button>
                            <DataTableViewOptions table={table} />
                        </div>
                    </DataTableCardToolbar>

                    <Collapsible open={filtersOpen} onOpenChange={setFiltersOpen}>
                        <CollapsibleContent className="animate-in slide-in-from-top-1 fade-in-50 duration-200">
                            <div className="border-y bg-muted/30 p-4">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 items-end">
                                    <div className="grid gap-1.5">
                                        <Label className="text-xs font-semibold text-muted-foreground">{t('Confirmation status')}</Label>
                                        <Select
                                            value={draft.confirmation_status ?? NONE}
                                            onValueChange={(value) =>
                                                updateFilters({
                                                    confirmation_status:
                                                        value === NONE ? undefined : value,
                                                })
                                            }
                                        >
                                            <SelectTrigger className="w-full h-9">
                                                <SelectValue placeholder={t('All statuses')} />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NONE}>
                                                    {t('All statuses')}
                                                </SelectItem>
                                                {Object.entries(
                                                    confirmationStatusLabels,
                                                ).map(([value, label]) => (
                                                    <SelectItem key={value} value={value}>
                                                        {label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label className="text-xs font-semibold text-muted-foreground">{t('Delivery status')}</Label>
                                        <Select
                                            value={draft.delivery_status ?? NONE}
                                            onValueChange={(value) =>
                                                updateFilters({
                                                    delivery_status:
                                                        value === NONE ? undefined : value,
                                                })
                                            }
                                        >
                                            <SelectTrigger className="w-full h-9">
                                                <SelectValue placeholder={t('All statuses')} />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NONE}>
                                                    {t('All statuses')}
                                                </SelectItem>
                                                {Object.entries(
                                                    deliveryStatusLabels,
                                                ).map(([value, label]) => (
                                                    <SelectItem key={value} value={value}>
                                                        {label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor="orders-store-filter"
                                            className="text-xs font-semibold text-muted-foreground"
                                        >
                                            Store
                                        </Label>
                                        <MultiCombobox
                                            id="orders-store-filter"
                                            className="h-9 w-full"
                                            options={stores.map((store) => ({
                                                value: String(store.id),
                                                label: store.name,
                                            }))}
                                            value={selectedStores}
                                            onChange={(next) =>
                                                updateFilters({
                                                    store_ids:
                                                        next.length > 0
                                                            ? next.join(',')
                                                            : undefined,
                                                })
                                            }
                                            placeholder={t('All stores')}
                                            searchPlaceholder="Search stores…"
                                            emptyMessage={t('No stores found.')}
                                        />
                                    </div>

                                    {isAdmin && (
                                        <div className="grid gap-1.5">
                                            <Label className="text-xs font-semibold text-muted-foreground">{t('Agent')}</Label>
                                            <Select
                                                value={draft.assigned_agent_id ?? NONE}
                                                onValueChange={(value) =>
                                                    updateFilters({
                                                        assigned_agent_id:
                                                            value === NONE
                                                                ? undefined
                                                                : value,
                                                    })
                                                }
                                            >
                                                <SelectTrigger className="w-full h-9">
                                                    <SelectValue placeholder={t('All agents')} />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={NONE}>
                                                        {t('All agents')}
                                                    </SelectItem>
                                                    <SelectItem value="__unassigned__">
                                                        {t('Unassigned only')}
                                                    </SelectItem>
                                                    {agents.map((agent) => (
                                                        <SelectItem
                                                            key={agent.id}
                                                            value={String(agent.id)}
                                                        >
                                                            {agent.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    )}

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="date_from" className="text-xs font-semibold text-muted-foreground">{t('From')}</Label>
                                        <DatePicker
                                            value={draft.date_from}
                                            onChange={(value) =>
                                                updateFilters({ date_from: value })
                                            }
                                            placeholder={t('Any date')}
                                            className="w-full h-9"
                                        />
                                    </div>

                                    <div className="grid gap-1.5">
                                        <Label htmlFor="date_to" className="text-xs font-semibold text-muted-foreground">{t('To')}</Label>
                                        <DatePicker
                                            value={draft.date_to}
                                            onChange={(value) =>
                                                updateFilters({ date_to: value })
                                            }
                                            placeholder={t('Any date')}
                                            className="w-full h-9"
                                        />
                                    </div>
                                </div>
                                {hasActiveFilters && (
                                    <div className="mt-3 flex justify-end">
                                        <DataTableResetFiltersButton
                                            onReset={handleReset}
                                        />
                                    </div>
                                )}
                            </div>
                        </CollapsibleContent>
                    </Collapsible>

                    <DataTableCardTable>
                        <DataTable
                            table={table}
                            columnCount={columns.length}
                            emptyMessage={t('No orders yet.')}
                        />
                    </DataTableCardTable>

                    <DataTableCardFooter>
                        <div className="flex w-full flex-wrap items-center justify-between gap-4">
                            <DataTablePerPageSelect
                                routeUrl={ordersIndex().url}
                                query={filters}
                                value={Number(filters.per_page ?? 20)}
                            />
                            <DataTablePaginationServer paginated={orders} />
                        </div>
                    </DataTableCardFooter>
                </DataTableCard>
            </div>

            <OrderFormDialog
                open={formOpen || editOrder !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setFormOpen(false);
                        setEditOrder(null);
                    }
                }}
                order={editOrder}
            />
            <OrderViewDialog
                open={viewOrder !== null}
                onOpenChange={(open) => !open && setViewOrder(null)}
                order={viewOrder}
                onCreateShipment={setShipmentOrder}
                agents={agents}
            />
            <OrderDeleteDialog
                open={deleteOrder !== null}
                onOpenChange={(open) => !open && setDeleteOrder(null)}
                order={deleteOrder}
            />
            <CreateShipmentDialog
                open={shipmentOrder !== null}
                onOpenChange={(open) => !open && setShipmentOrder(null)}
                order={shipmentOrder}
                deliveryAccounts={deliveryAccounts}
            />
            <CancelOrderDialog
                open={cancelOrder !== null}
                onOpenChange={(open) => !open && setCancelOrder(null)}
                order={cancelOrder}
            />
            <BlacklistOrderDialog
                open={blacklistOrder !== null}
                onOpenChange={(open) => !open && setBlacklistOrder(null)}
                order={blacklistOrder}
            />
            <OrderBulkDeleteDialog
                open={bulkDeleteOpen}
                onOpenChange={setBulkDeleteOpen}
                orderIds={selectedOrderIds}
                onDeleted={() => setRowSelection({})}
            />
            <OrderBulkAssignDialog
                open={bulkAssignOpen}
                onOpenChange={setBulkAssignOpen}
                orderIds={selectedOrderIds}
                agents={agents}
                getInitials={getInitials}
                onAssigned={() => setRowSelection({})}
            />
            <OrderBulkStatusDialog
                open={bulkStatusOpen}
                onOpenChange={setBulkStatusOpen}
                orderIds={selectedOrderIds}
                onUpdated={() => setRowSelection({})}
            />

            <StoreSyncCommand
                open={syncOpen}
                onOpenChange={setSyncOpen}
                stores={stores}
                onSelect={loadOrders}
                noun="orders"
            />
        </>
    );
}

OrdersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Orders',
            href: '/orders',
        },
    ],
};
