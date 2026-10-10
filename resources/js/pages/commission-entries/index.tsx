import { Form, Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    CircleDollarSign,
    Clock,
    Eye,
    FileText,
    Receipt,
    Search,
    Users,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import CommissionEntryController, {
    index as commissionEntriesIndex,
    generateInvoicesFromFilters,
} from '@/actions/App/Http/Controllers/Commissions/CommissionEntryController';
import {
    InvoiceSheet,
    invoicePayChip,
} from '@/components/commissions/invoice-sheet';
import {
    DataTableCard,
    DataTableCardFilters,
    DataTableCardFooter,
    DataTableCardTable,
} from '@/components/data-table/data-table-card';
import { DataTableColumnHeaderServer } from '@/components/data-table/data-table-column-header-server';
import { DataTablePaginationServer } from '@/components/data-table/data-table-pagination-server';
import { DataTablePerPageSelect } from '@/components/data-table/data-table-per-page-select';
import { DataTableResetFiltersButton } from '@/components/data-table/data-table-reset-filters-button';
import Heading from '@/components/heading';
import { PILL_TABS_LIST, PILL_TABS_TRIGGER } from '@/components/pill-tabs';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DatePicker } from '@/components/ui/date-picker';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useInitials } from '@/hooks/use-initials';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import { formatDate, formatDateRange, formatDateTime } from '@/lib/format';
import { statusChipClass } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type {
    CommissionLedgerEntry,
    Invoice,
    InvoiceStatus,
    Paginated,
} from '@/types';

const NONE = '__all__';

type Agent = { id: number; name: string; avatar: string | null };
type AgentTotals = {
    entries: number;
    uninvoiced: number;
    last_entry_at: string | null;
    earned: number;
    paid: number;
    pending: number;
};
type AgentSortKey = 'name' | 'entries' | 'earned' | 'pending' | 'last';
type CommissionFilters = {
    per_page?: string;
    agent_id?: string;
    date_from?: string;
    date_to?: string;
    min_amount?: string;
    max_amount?: string;
    /** Entries tab: an invoice status, or `uninvoiced` for entries with none. */
    status?: string;
    /** Invoices tab — kept separate so the two tabs filter independently. */
    invoice_status?: string;
    invoice_agent?: string;
    invoices_per_page?: string;
    sort?: string;
    direction?: string;
};

/** What earned an order entry, from the rule's trigger status. */
const TRIGGER_LABELS: Record<string, string> = {
    confirmed: 'Confirmation',
    delivered: 'Delivery',
    ready_for_pickup: 'Preparation',
    submitted_to_courier: 'Shipping',
};

type EntryWithRule = CommissionLedgerEntry & {
    commission_rule?: { id: number; trigger_status: string | null } | null;
};

/** "EF-10428 · Confirmation", "Posture Pro · Videos ×3", "Bonus · …". */
function entryLabel(entry: EntryWithRule, t: (key: string) => string): string {
    const reference =
        entry.order?.reference ??
        (entry.order_id ? `#${entry.order_id}` : null);
    const what =
        entry.description ??
        (entry.entry_type === 'reversal'
            ? t('Reversal')
            : entry.commission_rule?.trigger_status
              ? t(
                    TRIGGER_LABELS[entry.commission_rule.trigger_status] ??
                        entry.commission_rule.trigger_status,
                )
              : null);

    return [reference, what].filter(Boolean).join(' · ') || '—';
}

/** A KPI card per the platform skin: coloured label, tinted icon, mono value. */
function KpiCard({
    label,
    value,
    unit,
    icon: Icon,
    color,
    text,
}: {
    label: string;
    value: string;
    unit?: string;
    icon: LucideIcon;
    color: string;
    text: string;
}) {
    return (
        <div className="rounded-xl border border-border bg-card p-5 shadow-xs transition-colors hover:bg-muted/30">
            <div className="flex items-start justify-between gap-2">
                <p className="text-sm font-medium" style={{ color: text }}>
                    {label}
                </p>
                <span
                    className="flex size-8 shrink-0 items-center justify-center rounded-full"
                    style={{ background: `${color}1a`, color: text }}
                >
                    <Icon className="size-[18px]" />
                </span>
            </div>
            <p className="mt-3 leading-none">
                <span className="font-mono text-[26px] font-semibold tabular-nums">
                    {value}
                </span>
                {unit && (
                    <span className="ml-1 text-xs text-muted-foreground">
                        {unit}
                    </span>
                )}
            </p>
        </div>
    );
}

const TAB_LIST = cn(PILL_TABS_LIST, 'border border-border bg-muted/40');
const TAB_TRIGGER = PILL_TABS_TRIGGER;
const FILTER_LABEL = 'text-sm font-medium text-foreground';
const FIELD = 'h-9 w-full rounded-md bg-card text-sm shadow-xs';
/** Outline row action, same on the Invoices and By agent tables. */
const ROW_ACTION =
    'h-8 gap-1.5 rounded-md bg-card px-3 text-xs font-medium shadow-xs hover:bg-accent hover:text-accent-foreground [&_svg]:size-3.5';

const UNINVOICED = 'uninvoiced';

/** Mirrors CommissionEntryController::INVOICE_STATUSES. */
const INVOICE_STATUS_OPTIONS: { value: InvoiceStatus; label: string }[] = [
    { value: 'draft', label: 'Draft' },
    { value: 'issued', label: 'Issued' },
    { value: 'paid', label: 'Paid' },
    { value: 'cancelled', label: 'Cancelled' },
];

/** Entries tab: invoiced petrol, paid teal, cancelled red. */
function entryChipClass(status: InvoiceStatus): string {
    if (status === 'paid') {
        return 'bg-[#16A08E]/10 text-[#0C7D6F] dark:text-[#4CC7B4]';
    }

    if (status === 'cancelled') {
        return 'bg-[#D92D20]/10 text-[#B3281D] dark:text-[#F0766C]';
    }

    return 'bg-[#468FA5]/12 text-[#2E7389] dark:text-[#7DB5C7]';
}

function money(value: string | number): string {
    return `${Number(value).toFixed(2)} MAD`;
}

export default function CommissionEntriesIndex({
    entries,
    totalsByAgent,
    agents,
    invoices,
    isAdmin,
    filters,
    invoiceableCount,
    invoiceableAgentCount,
}: {
    entries: Paginated<CommissionLedgerEntry>;
    totalsByAgent: Record<number, AgentTotals>;
    agents: Agent[];
    invoices: Paginated<Invoice>;
    isAdmin: boolean;
    filters: CommissionFilters;
    /** Uninvoiced entries matching the current filters, across all pages. */
    invoiceableCount: number;
    /** Distinct agents among those entries — one invoice is created per agent. */
    invoiceableAgentCount: number;
}) {
    const { t } = useTranslation();

    const getInitials = useInitials();

    // Aggregated server-side over the whole ledger, never the filters or
    // the current page, so the tiles hold still while filtering.
    const totalsList = Object.values(totalsByAgent);
    const summary = {
        earned: totalsList.reduce((sum, row) => sum + row.earned, 0),
        paid: totalsList.reduce((sum, row) => sum + row.paid, 0),
        pending: totalsList.reduce((sum, row) => sum + row.pending, 0),
        uninvoiced: totalsList.reduce((sum, row) => sum + row.uninvoiced, 0),
        earningAgents: totalsList.filter((row) => row.earned > 0).length,
    };
    const amount = (value: number) =>
        value.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
            useGrouping: false,
        });

    const sortHeader = (title: string, sortKey: string) => (
        <DataTableColumnHeaderServer
            title={title}
            sortKey={sortKey}
            currentSort={filters.sort}
            currentDirection={filters.direction}
            routeUrl={commissionEntriesIndex().url}
            query={filters}
        />
    );
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [tab, setTab] = useState('entries');
    const [agentQuery, setAgentQuery] = useState('');
    const [onlyWithBalance, setOnlyWithBalance] = useState(false);
    const [agentSort, setAgentSort] = useState<{
        key: AgentSortKey;
        dir: 1 | -1;
    }>({ key: 'earned', dir: -1 });
    const [bulkOpen, setBulkOpen] = useState(false);
    const [bulkProcessing, setBulkProcessing] = useState(false);

    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters<CommissionFilters>(
            commissionEntriesIndex().url,
            filters,
            [
                'agent_id',
                'date_from',
                'date_to',
                'min_amount',
                'max_amount',
                'status',
            ],
        );

    /** Only the entry-scoping filters — pagination keys must not ride along. */
    const activeFilterQuery = Object.fromEntries(
        (
            [
                'agent_id',
                'date_from',
                'date_to',
                'min_amount',
                'max_amount',
                'status',
            ] as const
        )
            .filter((key) => filters[key])
            .map((key) => [key, filters[key] as string]),
    );

    /**
     * Restates the filters in words inside the confirmation, so the admin
     * confirms against what the action will actually match rather than
     * against the filter controls they set some scrolls ago.
     */
    const activeFilterSummary = [
        filters.agent_id
            ? `${t('agent')}: ${
                  agents.find((agent) => String(agent.id) === filters.agent_id)
                      ?.name ?? filters.agent_id
              }`
            : null,
        filters.date_from || filters.date_to
            ? `${t('dates')}: ${filters.date_from ?? t('any')} → ${filters.date_to ?? t('any')}`
            : null,
        filters.min_amount ? `${t('min')} ${money(filters.min_amount)}` : null,
        filters.max_amount ? `${t('max')} ${money(filters.max_amount)}` : null,
        filters.status
            ? `${t('status')}: ${
                  filters.status === UNINVOICED
                      ? t('Not invoiced')
                      : t(
                            INVOICE_STATUS_OPTIONS.find(
                                (option) => option.value === filters.status,
                            )?.label ?? filters.status,
                        )
              }`
            : null,
    ]
        .filter(Boolean)
        .join(', ');

    /**
     * The invoices tab filters on its own key, so it can't go through
     * `useTableFilters` (bound to the entries keys). Every other filter is
     * carried over untouched; `invoices_page` is dropped so a narrowed list
     * doesn't land on a page that no longer exists.
     */
    const setInvoiceFilter = (
        key: 'invoice_status' | 'invoice_agent',
        value: string | undefined,
    ) => {
        const next: Record<string, string | undefined> = {
            ...(filters as Record<string, string | undefined>),
            [key]: value,
        };

        delete next.invoices_page;

        router.get(commissionEntriesIndex().url, next, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const setInvoiceStatus = (value: string | undefined) =>
        setInvoiceFilter('invoice_status', value);

    const [previewInvoiceId, setPreviewInvoiceId] = useState<number | null>(
        null,
    );

    /**
     * "View entries": the agent's FULL history. Every other entries filter
     * is cleared and the page reset (draft carries no page key), so the
     * footer's total equals the Entries count on the By agent row.
     */
    const viewAgentEntries = (agentId: number) => {
        setSelectedIds([]);
        updateFilters({
            agent_id: String(agentId),
            status: undefined,
            date_from: undefined,
            date_to: undefined,
            min_amount: undefined,
            max_amount: undefined,
        });
        setTab('entries');
    };

    const agentRows = agents
        .map((agent) => {
            const totals = totalsByAgent[agent.id];

            return {
                ...agent,
                entries: totals?.entries ?? 0,
                earned: totals?.earned ?? 0,
                pending: totals?.pending ?? 0,
                lastEntryAt: totals?.last_entry_at ?? null,
            };
        })
        .filter(
            (agent) =>
                agent.name
                    .toLowerCase()
                    .includes(agentQuery.trim().toLowerCase()) &&
                (!onlyWithBalance || agent.earned > 0),
        )
        .sort((a, b) => {
            const value = (row: typeof a): string | number =>
                agentSort.key === 'name'
                    ? row.name.toLowerCase()
                    : agentSort.key === 'last'
                      ? (row.lastEntryAt ?? '')
                      : row[agentSort.key];
            const va = value(a);
            const vb = value(b);

            return va < vb ? -agentSort.dir : va > vb ? agentSort.dir : 0;
        });

    const agentSortHeader = (title: string, key: AgentSortKey) => {
        const active = agentSort.key === key;

        return (
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className={cn(
                    'ml-0 h-8 gap-1 px-0 text-xs font-medium hover:bg-transparent hover:text-foreground [&_svg]:size-3.5',
                    active
                        ? 'text-foreground [&_svg]:opacity-90'
                        : 'text-muted-foreground [&_svg]:opacity-40',
                )}
                onClick={() =>
                    setAgentSort((current) =>
                        current.key === key
                            ? { key, dir: current.dir === 1 ? -1 : 1 }
                            : { key, dir: key === 'name' ? 1 : -1 },
                    )
                }
            >
                {title}
                {!active ? (
                    <ChevronsUpDown />
                ) : agentSort.dir === 1 ? (
                    <ArrowUp />
                ) : (
                    <ArrowDown />
                )}
            </Button>
        );
    };

    const entryRows = entries.data;
    const invoiceRows = invoices.data;
    const selectableRows = entryRows.filter(
        (entry) => entry.invoice_id === null,
    );

    // A selection can span multiple agents: generating groups the entries by
    // agent server-side and creates one invoice per agent.
    const toggleEntry = (entry: CommissionLedgerEntry) => {
        setSelectedIds((current) =>
            current.includes(entry.id)
                ? current.filter((id) => id !== entry.id)
                : [...current, entry.id],
        );
    };

    const allSelected =
        selectableRows.length > 0 &&
        selectableRows.every((entry) => selectedIds.includes(entry.id));
    const someSelected = selectedIds.length > 0 && !allSelected;

    const toggleSelectAll = () => {
        setSelectedIds(
            allSelected ? [] : selectableRows.map((entry) => entry.id),
        );
    };

    const selectedAgentNames = Array.from(
        new Set(
            entryRows
                .filter((entry) => selectedIds.includes(entry.id))
                .map((entry) => entry.user?.name)
                .filter((name): name is string => Boolean(name)),
        ),
    );

    return (
        <>
            <Head title={t('Commissions')} />

            <div className="space-y-6 p-4">
                <Heading
                    title={t('Commissions')}
                    description={
                        isAdmin
                            ? t(
                                  'Track what each agent has earned, generate invoices, and mark them paid.',
                              )
                            : t('Track what you have earned so far.')
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <KpiCard
                        label={t('Total earned')}
                        value={amount(summary.earned)}
                        unit="MAD"
                        icon={Wallet}
                        color="#16A08E"
                        text="#0C7D6F"
                    />
                    <KpiCard
                        label={t('Pending payout')}
                        value={amount(summary.pending)}
                        unit="MAD"
                        icon={Clock}
                        color="#EFA22C"
                        text="#A87110"
                    />
                    <KpiCard
                        label={t('Awaiting invoice')}
                        value={String(summary.uninvoiced)}
                        unit={
                            summary.uninvoiced === 1 ? t('entry') : t('entries')
                        }
                        icon={FileText}
                        color="#468FA5"
                        text="#2E7389"
                    />
                    {isAdmin ? (
                        <KpiCard
                            label={t('Agents earning')}
                            value={String(summary.earningAgents)}
                            unit={t('of :count', { count: agents.length })}
                            icon={Users}
                            color="#49183C"
                            text="#49183C"
                        />
                    ) : (
                        <KpiCard
                            label={t('Paid')}
                            value={amount(summary.paid)}
                            unit="MAD"
                            icon={CircleDollarSign}
                            color="#49183C"
                            text="#49183C"
                        />
                    )}
                </div>

                <Tabs value={tab} onValueChange={setTab}>
                    <TabsList className={TAB_LIST}>
                        <TabsTrigger value="entries" className={TAB_TRIGGER}>
                            {t('Entries')}
                        </TabsTrigger>
                        <TabsTrigger value="invoices" className={TAB_TRIGGER}>
                            {t('Invoices')}
                        </TabsTrigger>
                        {isAdmin && (
                            <TabsTrigger value="agents" className={TAB_TRIGGER}>
                                {t('By agent')}
                            </TabsTrigger>
                        )}
                    </TabsList>

                    <TabsContent value="entries" className="space-y-4 pt-4">
                        {isAdmin && selectedIds.length > 0 && (
                            <Form
                                {...CommissionEntryController.generateInvoice.form()}
                                onSuccess={() => setSelectedIds([])}
                                className="flex items-center gap-3 rounded-lg border bg-muted/40 p-3"
                            >
                                {({ processing }) => (
                                    <>
                                        {selectedIds.map((id) => (
                                            <input
                                                key={id}
                                                type="hidden"
                                                name="entry_ids[]"
                                                value={id}
                                            />
                                        ))}
                                        <span className="text-sm">
                                            {t(':count entries selected', {
                                                count: selectedIds.length,
                                            })}
                                            {selectedAgentNames.length > 1
                                                ? ' ' +
                                                  t(
                                                      'across :count agents — will generate :count invoices',
                                                      {
                                                          count: selectedAgentNames.length,
                                                      },
                                                  )
                                                : ''}
                                        </span>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            {selectedAgentNames.length > 1
                                                ? t('Generate invoices')
                                                : t('Generate invoice')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}

                        <DataTableCard>
                            {/* The `auto` last track sizes to the action
                                buttons so the Invoice label never clips. */}
                            <DataTableCardFilters
                                className={cn(
                                    'grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-3',
                                    isAdmin
                                        ? 'xl:grid-cols-[repeat(6,minmax(0,1fr))_auto]'
                                        : 'xl:grid-cols-[repeat(5,minmax(0,1fr))_auto]',
                                )}
                            >
                                {isAdmin && (
                                    <div className="grid gap-1.5">
                                        <Label className={FILTER_LABEL}>
                                            {t('Agent')}
                                        </Label>
                                        <Select
                                            value={draft.agent_id ?? NONE}
                                            onValueChange={(value) =>
                                                updateFilters({
                                                    agent_id:
                                                        value === NONE
                                                            ? undefined
                                                            : value,
                                                })
                                            }
                                        >
                                            <SelectTrigger className={FIELD}>
                                                <SelectValue
                                                    placeholder={t(
                                                        'All agents',
                                                    )}
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NONE}>
                                                    {t('All agents')}
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

                                {/* An entry's status is its invoice's status;
                                    "Not invoiced" covers the ones with none,
                                    which is the set the bulk action bundles. */}
                                <div className="grid gap-1.5">
                                    <Label className={FILTER_LABEL}>
                                        {t('Status')}
                                    </Label>
                                    <Select
                                        value={draft.status ?? NONE}
                                        onValueChange={(value) =>
                                            updateFilters({
                                                status:
                                                    value === NONE
                                                        ? undefined
                                                        : value,
                                            })
                                        }
                                    >
                                        <SelectTrigger className={FIELD}>
                                            <SelectValue
                                                placeholder={t('Any status')}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>
                                                {t('Any status')}
                                            </SelectItem>
                                            <SelectItem value={UNINVOICED}>
                                                {t('Not invoiced')}
                                            </SelectItem>
                                            {INVOICE_STATUS_OPTIONS.map(
                                                (option) => (
                                                    <SelectItem
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {t(option.label)}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label className={FILTER_LABEL}>
                                        {t('From')}
                                    </Label>
                                    <DatePicker
                                        value={draft.date_from}
                                        onChange={(value) =>
                                            updateFilters({
                                                date_from: value,
                                            })
                                        }
                                        placeholder={t('Any date')}
                                        className={cn(FIELD, 'font-mono')}
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label className={FILTER_LABEL}>
                                        {t('To')}
                                    </Label>
                                    <DatePicker
                                        value={draft.date_to}
                                        onChange={(value) =>
                                            updateFilters({ date_to: value })
                                        }
                                        placeholder={t('Any date')}
                                        className={cn(FIELD, 'font-mono')}
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label className={FILTER_LABEL}>
                                        {t('Min amount')}
                                    </Label>
                                    <Input
                                        type="number"
                                        inputMode="decimal"
                                        placeholder="0"
                                        className={cn(
                                            FIELD,
                                            'font-mono tabular-nums',
                                        )}
                                        value={draft.min_amount ?? ''}
                                        onChange={(e) =>
                                            updateFilters({
                                                min_amount:
                                                    e.target.value || undefined,
                                            })
                                        }
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label className={FILTER_LABEL}>
                                        {t('Max amount')}
                                    </Label>
                                    <Input
                                        type="number"
                                        inputMode="decimal"
                                        placeholder={t('Any')}
                                        className={cn(
                                            FIELD,
                                            'font-mono tabular-nums',
                                        )}
                                        value={draft.max_amount ?? ''}
                                        onChange={(e) =>
                                            updateFilters({
                                                max_amount:
                                                    e.target.value || undefined,
                                            })
                                        }
                                    />
                                </div>

                                <div className="flex items-center gap-2">
                                    {hasActiveFilters && (
                                        <DataTableResetFiltersButton
                                            onReset={resetFilters}
                                        />
                                    )}

                                    {/* Invoices everything the filters match,
                                        not just the ticked rows on this page —
                                        the per-page checkboxes can't express
                                        "the whole of July for this agent" once
                                        it spans more than one page. It lives
                                        in the Entries tab, so the other tabs
                                        never show it. */}
                                    {isAdmin && (
                                        <Button
                                            type="button"
                                            className="h-9 gap-2 px-4 font-semibold whitespace-nowrap shadow-xs hover:opacity-90 disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0"
                                            disabled={invoiceableCount === 0}
                                            onClick={() => setBulkOpen(true)}
                                        >
                                            <CircleDollarSign />
                                            {invoiceableCount === 1
                                                ? t(
                                                      'Invoice :count matching entry',
                                                      {
                                                          count: invoiceableCount,
                                                      },
                                                  )
                                                : t(
                                                      'Invoice :count matching entries',
                                                      {
                                                          count: invoiceableCount,
                                                      },
                                                  )}
                                        </Button>
                                    )}
                                </div>
                            </DataTableCardFilters>

                            <DataTableCardTable>
                                <Table className="min-w-[900px]">
                                    <TableHeader>
                                        <TableRow>
                                            {isAdmin && (
                                                <TableHead className="w-8">
                                                    {selectableRows.length >
                                                        0 && (
                                                        <Checkbox
                                                            checked={
                                                                allSelected
                                                                    ? true
                                                                    : someSelected
                                                                      ? 'indeterminate'
                                                                      : false
                                                            }
                                                            onCheckedChange={
                                                                toggleSelectAll
                                                            }
                                                            aria-label={t(
                                                                'Select all rows',
                                                            )}
                                                        />
                                                    )}
                                                </TableHead>
                                            )}
                                            {isAdmin && (
                                                <TableHead>
                                                    {t('Agent')}
                                                </TableHead>
                                            )}
                                            <TableHead>{t('Order')}</TableHead>
                                            <TableHead>
                                                {sortHeader(
                                                    t('Amount'),
                                                    'amount',
                                                )}
                                            </TableHead>
                                            <TableHead>{t('Status')}</TableHead>
                                            <TableHead>
                                                {sortHeader(
                                                    t('Earned on'),
                                                    'created_at',
                                                )}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {entryRows.length === 0 ? (
                                            <TableRow className="hover:bg-transparent">
                                                <TableCell
                                                    colSpan={isAdmin ? 6 : 4}
                                                    className="p-0"
                                                >
                                                    <Empty className="border-none py-12">
                                                        <EmptyHeader>
                                                            <EmptyMedia variant="icon">
                                                                <Receipt />
                                                            </EmptyMedia>
                                                            <EmptyTitle>
                                                                {hasActiveFilters
                                                                    ? 'No entries match these filters'
                                                                    : 'No commission entries yet'}
                                                            </EmptyTitle>
                                                            <EmptyDescription>
                                                                {hasActiveFilters
                                                                    ? 'Adjust or clear the filters to see more entries.'
                                                                    : 'Entries appear here once an agent earns a commission on a delivered order.'}
                                                            </EmptyDescription>
                                                        </EmptyHeader>
                                                    </Empty>
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            entryRows.map((entry) => (
                                                <TableRow key={entry.id}>
                                                    {isAdmin && (
                                                        <TableCell>
                                                            {entry.invoice_id ===
                                                                null && (
                                                                <Checkbox
                                                                    checked={selectedIds.includes(
                                                                        entry.id,
                                                                    )}
                                                                    onCheckedChange={() =>
                                                                        toggleEntry(
                                                                            entry,
                                                                        )
                                                                    }
                                                                />
                                                            )}
                                                        </TableCell>
                                                    )}
                                                    {isAdmin && (
                                                        <TableCell className="text-sm font-medium whitespace-nowrap">
                                                            {entry.user?.name}
                                                        </TableCell>
                                                    )}
                                                    <TableCell className="text-sm whitespace-nowrap">
                                                        {entryLabel(
                                                            entry as EntryWithRule,
                                                            t,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-sm whitespace-nowrap">
                                                        <span className="font-mono font-semibold tabular-nums">
                                                            {Number(
                                                                entry.amount,
                                                            ).toFixed(2)}
                                                        </span>
                                                        <span className="ml-1 text-xs text-muted-foreground">
                                                            MAD
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        {entry.invoice ? (
                                                            <Badge
                                                                variant="outline"
                                                                className={cn(
                                                                    'border-transparent font-mono',
                                                                    statusChipClass,
                                                                    entryChipClass(
                                                                        entry
                                                                            .invoice
                                                                            .status,
                                                                    ),
                                                                )}
                                                            >
                                                                {
                                                                    entry
                                                                        .invoice
                                                                        .invoice_number
                                                                }
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="outline"
                                                                className={`border-transparent ${statusChipClass} bg-muted text-muted-foreground`}
                                                            >
                                                                {t(
                                                                    'Not invoiced',
                                                                )}
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                                        {formatDateTime(
                                                            entry.created_at,
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </DataTableCardTable>

                            {entryRows.length > 0 && (
                                <DataTableCardFooter>
                                    <DataTablePerPageSelect
                                        routeUrl={commissionEntriesIndex().url}
                                        query={filters}
                                        value={Number(filters.per_page ?? 20)}
                                    />
                                    <DataTablePaginationServer
                                        paginated={entries}
                                    />
                                </DataTableCardFooter>
                            )}
                        </DataTableCard>
                    </TabsContent>

                    <TabsContent value="invoices" className="space-y-4 pt-4">
                        <DataTableCard>
                            {/* Its own filter bar and its own query key: the
                                two tabs share a URL, so reusing the entries'
                                `status` here would re-scope both at once. */}
                            <DataTableCardFilters className="flex-wrap items-end">
                                <div className="grid gap-1.5">
                                    <Label className={FILTER_LABEL}>
                                        {t('Status')}
                                    </Label>
                                    <Select
                                        value={filters.invoice_status ?? NONE}
                                        onValueChange={(value) =>
                                            setInvoiceStatus(
                                                value === NONE
                                                    ? undefined
                                                    : value,
                                            )
                                        }
                                    >
                                        <SelectTrigger className="h-10 w-44">
                                            <SelectValue
                                                placeholder={t('Any status')}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>
                                                {t('Any status')}
                                            </SelectItem>
                                            {INVOICE_STATUS_OPTIONS.map(
                                                (option) => (
                                                    <SelectItem
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {t(option.label)}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {/* Agents only ever see their own invoices, so
                                    this filter would be a no-op for them. */}
                                {isAdmin && agents.length > 0 && (
                                    <div className="grid gap-1.5">
                                        <Label className={FILTER_LABEL}>
                                            {t('Agent')}
                                        </Label>
                                        <Select
                                            value={
                                                filters.invoice_agent ?? NONE
                                            }
                                            onValueChange={(value) =>
                                                setInvoiceFilter(
                                                    'invoice_agent',
                                                    value === NONE
                                                        ? undefined
                                                        : value,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="h-9 w-48">
                                                <SelectValue
                                                    placeholder={t('Any agent')}
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NONE}>
                                                    {t('Any agent')}
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

                                {(filters.invoice_status ||
                                    filters.invoice_agent) && (
                                    <DataTableResetFiltersButton
                                        onReset={() => {
                                            const next: Record<
                                                string,
                                                string | undefined
                                            > = {
                                                ...(filters as Record<
                                                    string,
                                                    string | undefined
                                                >),
                                                invoice_status: undefined,
                                                invoice_agent: undefined,
                                            };

                                            delete next.invoices_page;

                                            router.get(
                                                commissionEntriesIndex().url,
                                                next,
                                                {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                },
                                            );
                                        }}
                                    />
                                )}
                            </DataTableCardFilters>

                            <DataTableCardTable>
                                <Table className="min-w-[900px]">
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t('Invoice')}
                                            </TableHead>
                                            {isAdmin && (
                                                <TableHead>
                                                    {t('Agent')}
                                                </TableHead>
                                            )}
                                            <TableHead>{t('Period')}</TableHead>
                                            <TableHead>{t('Total')}</TableHead>
                                            <TableHead>{t('Status')}</TableHead>
                                            <TableHead className="text-right">
                                                {t('Actions')}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {invoiceRows.length === 0 ? (
                                            <TableRow className="hover:bg-transparent">
                                                <TableCell
                                                    colSpan={isAdmin ? 6 : 4}
                                                    className="p-0"
                                                >
                                                    <Empty className="border-none py-12">
                                                        <EmptyHeader>
                                                            <EmptyMedia variant="icon">
                                                                <Receipt />
                                                            </EmptyMedia>
                                                            {/* A filtered-out
                                                                list is not the
                                                                same as having
                                                                no invoices —
                                                                the first is
                                                                fixed by
                                                                clearing the
                                                                filter. */}
                                                            <EmptyTitle>
                                                                {filters.invoice_status
                                                                    ? 'No invoices with this status'
                                                                    : 'No invoices yet'}
                                                            </EmptyTitle>
                                                            <EmptyDescription>
                                                                {filters.invoice_status
                                                                    ? 'Try a different status, or clear the filter to see them all.'
                                                                    : 'Select unpaid entries above and generate an invoice to see it here.'}
                                                            </EmptyDescription>
                                                        </EmptyHeader>
                                                    </Empty>
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            invoiceRows.map((invoice) => (
                                                <TableRow key={invoice.id}>
                                                    <TableCell className="font-mono text-sm font-medium whitespace-nowrap">
                                                        {invoice.invoice_number}
                                                    </TableCell>
                                                    {isAdmin && (
                                                        <TableCell>
                                                            {invoice.user ? (
                                                                <div className="flex items-center gap-2">
                                                                    <Avatar className="size-7">
                                                                        <AvatarImage
                                                                            src={
                                                                                invoice
                                                                                    .user
                                                                                    .avatar ??
                                                                                undefined
                                                                            }
                                                                            alt=""
                                                                        />
                                                                        <AvatarFallback className="text-xs">
                                                                            {getInitials(
                                                                                invoice
                                                                                    .user
                                                                                    .name,
                                                                            )}
                                                                        </AvatarFallback>
                                                                    </Avatar>
                                                                    <span className="text-sm font-medium whitespace-nowrap">
                                                                        {
                                                                            invoice
                                                                                .user
                                                                                .name
                                                                        }
                                                                    </span>
                                                                </div>
                                                            ) : (
                                                                // user_id is nullable
                                                                // in the schema, so an
                                                                // ownerless invoice is
                                                                // possible and should
                                                                // read as such rather
                                                                // than as a blank cell.
                                                                <span className="text-muted-foreground">
                                                                    —
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                    )}
                                                    <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                                        {formatDateRange(
                                                            invoice.period_start,
                                                            invoice.period_end,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-sm whitespace-nowrap">
                                                        <span className="font-mono font-semibold tabular-nums">
                                                            {amount(
                                                                Number(
                                                                    invoice.total_amount,
                                                                ),
                                                            )}
                                                        </span>
                                                        <span className="ml-1 text-xs text-muted-foreground">
                                                            MAD
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant="outline"
                                                            className={`border-transparent ${invoicePayChip(invoice.status).className}`}
                                                        >
                                                            {t(
                                                                invoicePayChip(
                                                                    invoice.status,
                                                                ).label,
                                                            )}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            className={
                                                                ROW_ACTION
                                                            }
                                                            onClick={() =>
                                                                setPreviewInvoiceId(
                                                                    invoice.id,
                                                                )
                                                            }
                                                        >
                                                            <Eye />
                                                            {t('View details')}
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </DataTableCardTable>

                            {invoiceRows.length > 0 && (
                                <DataTableCardFooter>
                                    <DataTablePerPageSelect
                                        routeUrl={commissionEntriesIndex().url}
                                        query={filters}
                                        value={Number(
                                            filters.invoices_per_page ?? 20,
                                        )}
                                        perPageKey="invoices_per_page"
                                        pageKey="invoices_page"
                                    />
                                    <DataTablePaginationServer
                                        paginated={invoices}
                                    />
                                </DataTableCardFooter>
                            )}
                        </DataTableCard>
                    </TabsContent>
                    {isAdmin && (
                        <TabsContent value="agents" className="space-y-4 pt-4">
                            <DataTableCard>
                                <div className="flex flex-wrap items-center gap-3 p-4">
                                    <div className="relative w-full sm:w-72">
                                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            value={agentQuery}
                                            onChange={(e) =>
                                                setAgentQuery(e.target.value)
                                            }
                                            placeholder={t('Search agents…')}
                                            className="h-9 rounded-md bg-card pr-3 pl-9 text-sm shadow-xs"
                                        />
                                    </div>
                                    <Label className="ml-auto inline-flex cursor-pointer items-center gap-2 text-sm font-normal text-muted-foreground">
                                        <Checkbox
                                            checked={onlyWithBalance}
                                            onCheckedChange={(checked) =>
                                                setOnlyWithBalance(
                                                    checked === true,
                                                )
                                            }
                                        />
                                        {t('Only agents with a balance')}
                                    </Label>
                                </div>

                                <DataTableCardTable>
                                    <Table className="min-w-[820px]">
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    {agentSortHeader(
                                                        t('Agent'),
                                                        'name',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {agentSortHeader(
                                                        t('Entries'),
                                                        'entries',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {agentSortHeader(
                                                        t('Earned'),
                                                        'earned',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {agentSortHeader(
                                                        t('Pending'),
                                                        'pending',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {agentSortHeader(
                                                        t('Last entry'),
                                                        'last',
                                                    )}
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    {t('Actions')}
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {agentRows.length === 0 ? (
                                                <TableRow className="hover:bg-transparent">
                                                    <TableCell
                                                        colSpan={6}
                                                        className="px-4 py-14 text-center text-sm text-muted-foreground"
                                                    >
                                                        {t(
                                                            'No agents match this search.',
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ) : (
                                                agentRows.map((agent) => {
                                                    // Zero balances must not
                                                    // read as achievement.
                                                    const idle =
                                                        agent.earned === 0 &&
                                                        agent.pending === 0;

                                                    return (
                                                        <TableRow
                                                            key={agent.id}
                                                        >
                                                            <TableCell>
                                                                <div className="flex items-center gap-2.5">
                                                                    <Avatar className="size-8 border border-border">
                                                                        <AvatarImage
                                                                            src={
                                                                                agent.avatar ??
                                                                                undefined
                                                                            }
                                                                            alt={
                                                                                agent.name
                                                                            }
                                                                        />
                                                                        <AvatarFallback className="bg-secondary text-[11px] font-semibold text-secondary-foreground">
                                                                            {getInitials(
                                                                                agent.name,
                                                                            )}
                                                                        </AvatarFallback>
                                                                    </Avatar>
                                                                    <span className="text-sm font-medium whitespace-nowrap">
                                                                        {
                                                                            agent.name
                                                                        }
                                                                    </span>
                                                                </div>
                                                            </TableCell>
                                                            <TableCell
                                                                className={cn(
                                                                    'font-mono text-sm tabular-nums',
                                                                    agent.entries ===
                                                                        0 &&
                                                                        'text-muted-foreground',
                                                                )}
                                                            >
                                                                {agent.entries}
                                                            </TableCell>
                                                            {(
                                                                [
                                                                    [
                                                                        agent.earned,
                                                                        'text-[#0C7D6F] dark:text-[#4CC7B4]',
                                                                    ],
                                                                    [
                                                                        agent.pending,
                                                                        'text-[#A87110] dark:text-[#F3B95C]',
                                                                    ],
                                                                ] as [
                                                                    number,
                                                                    string,
                                                                ][]
                                                            ).map(
                                                                (
                                                                    [
                                                                        value,
                                                                        tone,
                                                                    ],
                                                                    i,
                                                                ) => (
                                                                    <TableCell
                                                                        key={i}
                                                                        className="text-sm whitespace-nowrap"
                                                                    >
                                                                        <span
                                                                            className={cn(
                                                                                'font-mono tabular-nums',
                                                                                idle
                                                                                    ? 'text-muted-foreground'
                                                                                    : cn(
                                                                                          'font-semibold',
                                                                                          tone,
                                                                                      ),
                                                                            )}
                                                                        >
                                                                            {amount(
                                                                                value,
                                                                            )}
                                                                        </span>
                                                                        <span className="ml-1 text-xs text-muted-foreground">
                                                                            MAD
                                                                        </span>
                                                                    </TableCell>
                                                                ),
                                                            )}
                                                            <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                                                {agent.lastEntryAt
                                                                    ? formatDate(
                                                                          agent.lastEntryAt.replace(
                                                                              ' ',
                                                                              'T',
                                                                          ),
                                                                      )
                                                                    : '—'}
                                                            </TableCell>
                                                            <TableCell className="text-right">
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className={
                                                                        ROW_ACTION
                                                                    }
                                                                    disabled={
                                                                        agent.entries ===
                                                                        0
                                                                    }
                                                                    onClick={() =>
                                                                        viewAgentEntries(
                                                                            agent.id,
                                                                        )
                                                                    }
                                                                >
                                                                    <Eye />
                                                                    {t(
                                                                        'View entries',
                                                                    )}
                                                                </Button>
                                                            </TableCell>
                                                        </TableRow>
                                                    );
                                                })
                                            )}
                                        </TableBody>
                                    </Table>
                                </DataTableCardTable>

                                <DataTableCardFooter>
                                    <p className="text-xs text-muted-foreground">
                                        {t('Showing :shown of :total agents', {
                                            shown: agentRows.length,
                                            total: agents.length,
                                        })}
                                    </p>
                                </DataTableCardFooter>
                            </DataTableCard>
                        </TabsContent>
                    )}
                </Tabs>
            </div>

            {/* Spells the scope out because the action reaches past what's on
                screen: it consumes every matching entry, including rows on
                pages the admin never looked at. */}
            <AlertDialog open={bulkOpen} onOpenChange={setBulkOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {invoiceableCount === 1
                                ? t('Invoice :count entry?', {
                                      count: invoiceableCount,
                                  })
                                : t('Invoice :count entries?', {
                                      count: invoiceableCount,
                                  })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t(
                                'This bundles every uninvoiced entry matching your current filters',
                            )}
                            {activeFilterSummary
                                ? ` (${activeFilterSummary})`
                                : ` (${t('no filters — all uninvoiced entries')})`}
                            {t(', across all pages, into')}{' '}
                            {invoiceableAgentCount === 1
                                ? t('one invoice')
                                : t(':count invoices', {
                                      count: invoiceableAgentCount,
                                  })}{' '}
                            {t(
                                "— one per agent. Invoiced entries can't be re-invoiced.",
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <Button
                            type="button"
                            disabled={bulkProcessing}
                            onClick={() => {
                                setBulkProcessing(true);
                                router.post(
                                    // Filters go in the body AND the query:
                                    // the body is what the controller reads
                                    // to resolve the entry set, the query is
                                    // what the post-action redirect preserves
                                    // so the admin lands back on this view.
                                    generateInvoicesFromFilters({
                                        query: activeFilterQuery,
                                    }).url,
                                    activeFilterQuery,
                                    {
                                        preserveScroll: true,
                                        onFinish: () => {
                                            setBulkProcessing(false);
                                            setBulkOpen(false);
                                            setSelectedIds([]);
                                        },
                                    },
                                );
                            }}
                        >
                            {bulkProcessing
                                ? 'Generating…'
                                : `Generate ${
                                      invoiceableAgentCount === 1
                                          ? 'invoice'
                                          : `${invoiceableAgentCount} invoices`
                                  }`}
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <InvoiceSheet
                invoiceId={previewInvoiceId}
                canMarkPaid={isAdmin}
                onOpenChange={(open) => !open && setPreviewInvoiceId(null)}
            />
        </>
    );
}

CommissionEntriesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Commissions',
            href: '/commission-entries',
        },
    ],
};
