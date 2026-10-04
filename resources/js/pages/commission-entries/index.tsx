import { Form, Head, router } from '@inertiajs/react';
import { Download, Eye, Receipt } from 'lucide-react';
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
};

const UNINVOICED = 'uninvoiced';

/** Mirrors CommissionEntryController::INVOICE_STATUSES. */
const INVOICE_STATUS_OPTIONS: { value: InvoiceStatus; label: string }[] = [
    { value: 'draft', label: 'Draft' },
    { value: 'issued', label: 'Issued' },
    { value: 'paid', label: 'Paid' },
    { value: 'cancelled', label: 'Cancelled' },
];

const invoiceStatusClasses: Record<InvoiceStatus, string> = {
    draft: 'bg-muted text-muted-foreground',
    issued: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    paid: 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
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
                            ? 'Track what each agent has earned, generate invoices, and mark them paid.'
                            : t('Track what you have earned so far.')
                    }
                />

                {isAdmin && agents.length > 0 && (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {agents.map((agent) => {
                            const totals = totalsByAgent[agent.id] ?? {
                                earned: 0,
                                paid: 0,
                                pending: 0,
                            };

                            return (
                                <div
                                    key={agent.id}
                                    className="flex items-center gap-3 rounded-lg border p-4"
                                >
                                    <Avatar className="size-10">
                                        <AvatarImage
                                            src={agent.avatar ?? undefined}
                                            alt={agent.name}
                                        />
                                        <AvatarFallback>
                                            {getInitials(agent.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="grid flex-1 gap-0.5">
                                        <span className="text-sm font-medium">
                                            {agent.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {t(
                                                'Earned :earned · Pending :pending',
                                                {
                                                    earned: money(
                                                        totals.earned,
                                                    ),
                                                    pending: money(
                                                        totals.pending,
                                                    ),
                                                },
                                            )}
                                        </span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                {!isAdmin &&
                    (() => {
                        const totals = Object.values(totalsByAgent)[0] ?? {
                            earned: 0,
                            paid: 0,
                            pending: 0,
                        };

                        return (
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div className="rounded-lg border p-4">
                                    <span className="text-xs text-muted-foreground">
                                        {t('Total earned')}
                                    </span>
                                    <p className="text-2xl font-semibold">
                                        {money(totals.earned)}
                                    </p>
                                </div>
                                <div className="rounded-lg border p-4">
                                    <span className="text-xs text-muted-foreground">
                                        {t('Paid')}
                                    </span>
                                    <p className="text-2xl font-semibold">
                                        {money(totals.paid)}
                                    </p>
                                </div>
                                <div className="rounded-lg border p-4">
                                    <span className="text-xs text-muted-foreground">
                                        {t('Pending payout')}
                                    </span>
                                    <p className="text-2xl font-semibold">
                                        {money(totals.pending)}
                                    </p>
                                </div>
                            </div>
                        );
                    })()}

                <Tabs defaultValue="entries">
                    <TabsList>
                        <TabsTrigger value="entries">
                            {t('Entries')}
                        </TabsTrigger>
                        <TabsTrigger value="invoices">
                            {t('Invoices')}
                        </TabsTrigger>
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
                                        <Label className="text-xs font-semibold text-muted-foreground">
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
                                            <SelectTrigger className="h-9 w-40">
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
                                    <Label className="text-xs font-semibold text-muted-foreground">
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
                                        <SelectTrigger className="h-9 w-40">
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
                                    <Label className="text-xs font-semibold text-muted-foreground">
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
                                        className="h-9 w-40"
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label className="text-xs font-semibold text-muted-foreground">
                                        {t('To')}
                                    </Label>
                                    <DatePicker
                                        value={draft.date_to}
                                        onChange={(value) =>
                                            updateFilters({ date_to: value })
                                        }
                                        placeholder={t('Any date')}
                                        className="h-9 w-40"
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label className="text-xs font-semibold text-muted-foreground">
                                        {t('Min amount')}
                                    </Label>
                                    <Input
                                        type="number"
                                        inputMode="decimal"
                                        placeholder="0"
                                        className="h-9 w-28"
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
                                    <Label className="text-xs font-semibold text-muted-foreground">
                                        {t('Max amount')}
                                    </Label>
                                    <Input
                                        type="number"
                                        inputMode="decimal"
                                        placeholder={t('Any')}
                                        className="h-9 w-28"
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
                                        variant="outline"
                                        size="sm"
                                        className="ml-auto"
                                        onClick={() => setBulkOpen(true)}
                                    >
                                        <Receipt />
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
                                            <TableHead>{t('Amount')}</TableHead>
                                            <TableHead>{t('Status')}</TableHead>
                                            <TableHead>
                                                {t('Earned on')}
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
                                                        <TableCell>
                                                            {entry.user?.name}
                                                        </TableCell>
                                                    )}
                                                    <TableCell>
                                                        {entry.order
                                                            ?.reference ??
                                                            `#${entry.order_id}`}
                                                    </TableCell>
                                                    <TableCell>
                                                        {money(entry.amount)}
                                                    </TableCell>
                                                    <TableCell>
                                                        {entry.invoice ? (
                                                            <Badge
                                                                variant="outline"
                                                                className={`border-transparent ${invoiceStatusClasses[entry.invoice.status]}`}
                                                            >
                                                                {
                                                                    entry
                                                                        .invoice
                                                                        .invoice_number
                                                                }
                                                            </Badge>
                                                        ) : (
                                                            <Badge variant="outline">
                                                                {t(
                                                                    'Not invoiced',
                                                                )}
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
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
                                    <Label className="text-xs font-semibold text-muted-foreground">
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
                                        <SelectTrigger className="h-9 w-40">
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
                                        <Label className="text-xs font-semibold text-muted-foreground">
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
                                            {isAdmin && (
                                                <TableHead className="text-right">
                                                    {t('Actions')}
                                                </TableHead>
                                            )}
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
                                                        {isAdmin ? (
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
                                                        ) : (
                                                            invoice.invoice_number
                                                        )}
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
                                                    <TableCell>
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
                                                    {isAdmin && (
                                                        <TableCell>
                                                            <div className="flex items-center justify-end gap-2">
                                                                {invoice.status !==
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
                                                                    {t(
                                                                        'Preview',
                                                                    )}
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
                                                                            )
                                                                                .url
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
                                                    )}
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
