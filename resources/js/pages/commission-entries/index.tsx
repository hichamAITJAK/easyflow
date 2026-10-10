import { Form, Head, router } from '@inertiajs/react';
import {
    CircleDollarSign,
    Clock,
    Download,
    Eye,
    FileText,
    Receipt,
    Users,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import CommissionEntryController, {
    index as commissionEntriesIndex,
    downloadInvoice,
    generateInvoicesFromFilters,
    markInvoicePaid,
} from '@/actions/App/Http/Controllers/Commissions/CommissionEntryController';
import { InvoicePreviewDialog } from '@/components/commissions/invoice-preview-dialog';
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
import { formatDateRange, formatDateTime } from '@/lib/format';
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
type AgentTotals = { earned: number; paid: number; pending: number };
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
        <div className="rounded-xl border border-border bg-card p-5 shadow-xs">
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
                    <span className="ml-1.5 text-xs font-medium text-muted-foreground">
                        {unit}
                    </span>
                )}
            </p>
        </div>
    );
}

const TAB_LIST =
    'h-auto flex-wrap justify-start gap-0 rounded-lg border border-border bg-muted p-1';
const TAB_TRIGGER =
    'h-auto flex-none rounded-md border-0 px-4 py-1.5 text-sm font-medium text-muted-foreground hover:text-foreground data-[state=active]:bg-card data-[state=active]:text-foreground data-[state=active]:shadow-xs';
const FILTER_LABEL = 'text-sm font-medium text-foreground';

const UNINVOICED = 'uninvoiced';

/** Mirrors CommissionEntryController::INVOICE_STATUSES. */
const INVOICE_STATUS_OPTIONS: { value: InvoiceStatus; label: string }[] = [
    { value: 'draft', label: 'Draft' },
    { value: 'issued', label: 'Issued' },
    { value: 'paid', label: 'Paid' },
    { value: 'cancelled', label: 'Cancelled' },
];

/* Platform palette: draft muted, issued mustard (waiting), paid teal,
   cancelled red — same hues as the order status chips. */
const invoiceStatusClasses: Record<InvoiceStatus, string> = {
    draft: `${statusChipClass} bg-muted text-muted-foreground`,
    issued: `${statusChipClass} bg-[#EFA22C]/12 text-[#A87110] dark:text-[#F3B95C]`,
    paid: `${statusChipClass} bg-[#16A08E]/10 text-[#0C7D6F] dark:text-[#4CC7B4]`,
    cancelled: `${statusChipClass} bg-[#D92D20]/10 text-[#B3281D] dark:text-[#F0766C]`,
};

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

    // Over every entry the filters match (not just this page): the
    // per-agent totals are aggregated server-side across the whole set.
    const totalsList = Object.values(totalsByAgent);
    const summary = {
        earned: totalsList.reduce((sum, row) => sum + row.earned, 0),
        paid: totalsList.reduce((sum, row) => sum + row.paid, 0),
        pending: totalsList.reduce((sum, row) => sum + row.pending, 0),
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
                        value={String(invoiceableCount)}
                        unit={
                            invoiceableCount === 1 ? t('entry') : t('entries')
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

                <Tabs defaultValue="entries">
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
                            <DataTableCardFilters className="flex-wrap items-end">
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
                                            <SelectTrigger className="h-10 w-44">
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
                                        <SelectTrigger className="h-10 w-44">
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
                                        className="h-10 w-44"
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
                                        className="h-10 w-44"
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
                                        className="h-10 w-44"
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
                                        className="h-10 w-44"
                                        value={draft.max_amount ?? ''}
                                        onChange={(e) =>
                                            updateFilters({
                                                max_amount:
                                                    e.target.value || undefined,
                                            })
                                        }
                                    />
                                </div>

                                {hasActiveFilters && (
                                    <DataTableResetFiltersButton
                                        onReset={resetFilters}
                                    />
                                )}

                                {/* Invoices everything the filters match, not
                                    just the ticked rows on this page — the
                                    per-page checkboxes can't express "the
                                    whole of July for this agent" once it
                                    spans more than one page. */}
                                {isAdmin && invoiceableCount > 0 && (
                                    <Button
                                        type="button"
                                        className="ml-auto h-10 gap-2 px-4 font-semibold hover:opacity-90"
                                        onClick={() => setBulkOpen(true)}
                                    >
                                        <CircleDollarSign />
                                        {invoiceableCount === 1
                                            ? t(
                                                  'Invoice :count matching entry',
                                                  { count: invoiceableCount },
                                              )
                                            : t(
                                                  'Invoice :count matching entries',
                                                  { count: invoiceableCount },
                                              )}
                                    </Button>
                                )}
                            </DataTableCardFilters>

                            <DataTableCardTable>
                                <Table>
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
                                                        <TableCell className="font-medium whitespace-nowrap">
                                                            {entry.user?.name}
                                                        </TableCell>
                                                    )}
                                                    <TableCell className="whitespace-nowrap">
                                                        {entryLabel(
                                                            entry as EntryWithRule,
                                                            t,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="whitespace-nowrap">
                                                        <span className="font-mono font-semibold tabular-nums">
                                                            {Number(
                                                                entry.amount,
                                                            ).toFixed(2)}
                                                        </span>{' '}
                                                        <span className="text-xs text-muted-foreground">
                                                            MAD
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        {entry.invoice ? (
                                                            <Badge
                                                                variant="outline"
                                                                className={cn(
                                                                    'border-transparent font-mono',
                                                                    invoiceStatusClasses[
                                                                        entry
                                                                            .invoice
                                                                            .status
                                                                    ],
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
                                                    <TableCell className="whitespace-nowrap text-muted-foreground">
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
                                <Table>
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
                                                    <TableCell>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setPreviewInvoiceId(
                                                                    invoice.id,
                                                                )
                                                            }
                                                            className="rounded-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                        >
                                                            {
                                                                invoice.invoice_number
                                                            }
                                                        </button>
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
                                                                    <span className="whitespace-nowrap">
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
                                                    <TableCell className="whitespace-nowrap">
                                                        {formatDateRange(
                                                            invoice.period_start,
                                                            invoice.period_end,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-mono font-semibold tabular-nums">
                                                        {money(
                                                            invoice.total_amount,
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant="outline"
                                                            className={`border-transparent ${invoiceStatusClasses[invoice.status]}`}
                                                        >
                                                            {invoice.status}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center justify-end gap-2">
                                                            {isAdmin &&
                                                                invoice.status !==
                                                                    'paid' && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        onClick={() =>
                                                                            router.patch(
                                                                                markInvoicePaid(
                                                                                    invoice.id,
                                                                                )
                                                                                    .url,
                                                                            )
                                                                        }
                                                                    >
                                                                        {t(
                                                                            'Mark as paid',
                                                                        )}
                                                                    </Button>
                                                                )}
                                                            {/* A real anchor, not
                                                            a router call — the
                                                            response is a file
                                                            download, so an
                                                            Inertia visit would
                                                            get a PDF body it
                                                            can't render. */}
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setPreviewInvoiceId(
                                                                        invoice.id,
                                                                    )
                                                                }
                                                                aria-label={t(
                                                                    'Preview invoice :number',
                                                                    {
                                                                        number: invoice.invoice_number,
                                                                    },
                                                                )}
                                                            >
                                                                <Eye />
                                                                {t('Preview')}
                                                            </Button>
                                                            <Button
                                                                asChild
                                                                size="sm"
                                                                variant="ghost"
                                                            >
                                                                <a
                                                                    href={
                                                                        downloadInvoice(
                                                                            invoice.id,
                                                                        ).url
                                                                    }
                                                                    aria-label={t(
                                                                        'Download invoice :number',
                                                                        {
                                                                            number: invoice.invoice_number,
                                                                        },
                                                                    )}
                                                                >
                                                                    <Download />
                                                                    {t(
                                                                        'Download',
                                                                    )}
                                                                </a>
                                                            </Button>
                                                        </div>
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
                                <DataTableCardTable>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    {t('Agent')}
                                                </TableHead>
                                                <TableHead>
                                                    {t('Earned')}
                                                </TableHead>
                                                <TableHead>
                                                    {t('Paid')}
                                                </TableHead>
                                                <TableHead>
                                                    {t('Pending payout')}
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {agents.map((agent) => {
                                                const totals = totalsByAgent[
                                                    agent.id
                                                ] ?? {
                                                    earned: 0,
                                                    paid: 0,
                                                    pending: 0,
                                                };

                                                return (
                                                    <TableRow key={agent.id}>
                                                        <TableCell>
                                                            <div className="flex items-center gap-2.5">
                                                                <Avatar className="size-7">
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
                                                                <span className="font-medium whitespace-nowrap">
                                                                    {agent.name}
                                                                </span>
                                                            </div>
                                                        </TableCell>
                                                        {(
                                                            [
                                                                [
                                                                    totals.earned,
                                                                    '',
                                                                ],
                                                                [
                                                                    totals.paid,
                                                                    'text-[#0C7D6F]',
                                                                ],
                                                                [
                                                                    totals.pending,
                                                                    totals.pending >
                                                                    0
                                                                        ? 'text-[#A87110]'
                                                                        : '',
                                                                ],
                                                            ] as [
                                                                number,
                                                                string,
                                                            ][]
                                                        ).map(
                                                            (
                                                                [value, tone],
                                                                i,
                                                            ) => (
                                                                <TableCell
                                                                    key={i}
                                                                    className="whitespace-nowrap"
                                                                >
                                                                    <span
                                                                        className={cn(
                                                                            'font-mono font-semibold tabular-nums',
                                                                            tone,
                                                                        )}
                                                                    >
                                                                        {amount(
                                                                            value,
                                                                        )}
                                                                    </span>{' '}
                                                                    <span className="text-xs text-muted-foreground">
                                                                        MAD
                                                                    </span>
                                                                </TableCell>
                                                            ),
                                                        )}
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </DataTableCardTable>
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

            <InvoicePreviewDialog
                invoiceId={previewInvoiceId}
                open={previewInvoiceId !== null}
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
