import { Head, router } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { BadgeCheck, ExternalLink, Inbox, Receipt } from 'lucide-react';
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
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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
import { Textarea } from '@/components/ui/textarea';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useTableFilters } from '@/hooks/use-table-filters';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import {
    approve as approveRequest,
    index as subscriptionsIndex,
    reject as rejectRequest,
} from '@/routes/super-admin/subscriptions';
import type {
    Paginated,
    SubscriptionHistoryFilters,
    SubscriptionRequest,
    SubscriptionStatus,
} from '@/types';
import { createHistoryColumns } from './history-columns';

const METHOD_LABELS: Record<string, string> = {
    bank_transfer: 'Bank transfer',
    cash: 'Cash',
};

type DialogState =
    | { mode: 'approve'; request: SubscriptionRequest }
    | { mode: 'reject'; request: SubscriptionRequest }
    | null;

const HISTORY_STATUSES: { value: SubscriptionStatus; label: string }[] = [
    { value: 'trialing', label: 'Trial' },
    { value: 'active', label: 'Active' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'expired', label: 'Expired' },
    { value: 'cancelled', label: 'Cancelled' },
];

/** Select can't hold an empty value, so "any" needs a sentinel. */
const ANY_STATUS = 'all';

const HISTORY_FILTER_KEYS: (keyof SubscriptionHistoryFilters)[] = [
    'history_search',
    'history_status',
];

export default function SuperAdminSubscriptionsIndex({
    pending,
    history,
    historyFilters,
}: {
    pending: SubscriptionRequest[];
    history: Paginated<SubscriptionRequest>;
    historyFilters: SubscriptionHistoryFilters;
}) {
    const [dialog, setDialog] = useState<DialogState>(null);
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);

    const historyUrl = subscriptionsIndex().url;

    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters(historyUrl, historyFilters, HISTORY_FILTER_KEYS);

    const [historySearch, setHistorySearch] = useState(
        historyFilters.history_search ?? '',
    );
    const debouncedHistorySearch = useDebouncedValue(historySearch, 300);
    const isFirstSearchRun = useRef(true);

    useEffect(() => {
        if (isFirstSearchRun.current) {
            isFirstSearchRun.current = false;

            return;
        }

        updateFilters({
            history_search: debouncedHistorySearch || undefined,
            history_page: undefined,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedHistorySearch]);

    const historyColumns = useMemo(
        () =>
            createHistoryColumns({
                filters: historyFilters,
                routeUrl: historyUrl,
            }),
        [historyFilters, historyUrl],
    );

    const historyTable = useReactTable({
        data: history.data,
        columns: historyColumns,
        getCoreRowModel: getCoreRowModel(),
    });

    const resetHistoryFilters = () => {
        setHistorySearch('');
        resetFilters();
    };

    const closeDialog = () => {
        setDialog(null);
        setNote('');
    };

    const submitDecision = () => {
        if (!dialog) {
            return;
        }

        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: closeDialog,
        };

        if (dialog.mode === 'approve') {
            router.patch(
                approveRequest(dialog.request.id).url,
                { notes: note || null },
                options,
            );
        } else {
            router.patch(
                rejectRequest(dialog.request.id).url,
                { reason: note },
                options,
            );
        }
    };

    return (
        <SuperAdminLayout>
            <Head title="Subscriptions" />

            <div className="space-y-10">
                <div className="space-y-6">
                    <Heading
                        title="Payment requests"
                        description="Businesses claiming a bank transfer or cash payment, waiting for review."
                    />

                    {pending.length === 0 ? (
                        <Empty className="border">
                            <EmptyHeader>
                                <EmptyMedia
                                    variant="default"
                                    aria-hidden="true"
                                >
                                    <div className="flex size-12 items-center justify-center rounded-full border-2 border-dashed text-muted-foreground">
                                        <Inbox className="size-5" />
                                    </div>
                                </EmptyMedia>
                                <EmptyTitle>Queue is clear</EmptyTitle>
                                <EmptyDescription>
                                    New payment requests land here the moment a
                                    business clicks “I have paid”.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <div className="overflow-hidden rounded-xl border bg-card shadow-2xs">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>Business</TableHead>
                                        <TableHead>Plan</TableHead>
                                        <TableHead>Reference</TableHead>
                                        <TableHead>Method</TableHead>
                                        <TableHead>Receipt</TableHead>
                                        <TableHead>Submitted</TableHead>
                                        <TableHead className="text-right">
                                            Decision
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {pending.map((request) => (
                                        <TableRow key={request.id}>
                                            <TableCell className="font-medium">
                                                {request.businessName}
                                            </TableCell>
                                            <TableCell>
                                                <div>{request.planName}</div>
                                                {request.planPrice && (
                                                    <div className="text-xs text-muted-foreground tabular-nums">
                                                        {Number(
                                                            request.planPrice,
                                                        ).toLocaleString()}{' '}
                                                        {request.planCurrency}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="font-mono text-xs">
                                                    {request.referenceCode}
                                                </div>
                                                {request.paymentReference && (
                                                    <div className="max-w-40 truncate text-xs text-muted-foreground">
                                                        {
                                                            request.paymentReference
                                                        }
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {request.paymentMethod
                                                    ? METHOD_LABELS[
                                                          request.paymentMethod
                                                      ]
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                {request.receiptUrl ? (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <a
                                                            href={
                                                                request.receiptUrl
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            View
                                                            <ExternalLink className="size-3.5" />
                                                        </a>
                                                    </Button>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {request.submittedAt ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setDialog({
                                                                mode: 'reject',
                                                                request,
                                                            })
                                                        }
                                                    >
                                                        Reject
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        onClick={() =>
                                                            setDialog({
                                                                mode: 'approve',
                                                                request,
                                                            })
                                                        }
                                                    >
                                                        <BadgeCheck className="size-4" />
                                                        Activate
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Subscription history"
                        description="Every trial, activation, rejection, and expiry across the platform."
                    />

                    <DataTableCard>
                        <DataTableCardToolbar>
                            <div className="flex flex-wrap items-center gap-2">
                                <Input
                                    className="w-full max-w-sm sm:w-64"
                                    placeholder="Search business or reference…"
                                    value={historySearch}
                                    onChange={(event) =>
                                        setHistorySearch(event.target.value)
                                    }
                                    aria-label="Search subscription history"
                                />
                                <Select
                                    value={draft.history_status ?? ANY_STATUS}
                                    onValueChange={(value) =>
                                        updateFilters({
                                            history_status:
                                                value === ANY_STATUS
                                                    ? undefined
                                                    : value,
                                            history_page: undefined,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        className="w-36"
                                        aria-label="Filter history by status"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY_STATUS}>
                                            Any status
                                        </SelectItem>
                                        {HISTORY_STATUSES.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {hasActiveFilters && (
                                    <DataTableResetFiltersButton
                                        onReset={resetHistoryFilters}
                                    />
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                <DataTablePerPageSelect
                                    routeUrl={historyUrl}
                                    query={historyFilters}
                                    value={Number(
                                        historyFilters.history_per_page ?? 20,
                                    )}
                                    perPageKey="history_per_page"
                                    pageKey="history_page"
                                    size="sm"
                                />
                                <DataTableViewOptions table={historyTable} />
                            </div>
                        </DataTableCardToolbar>

                        <DataTableCardTable>
                            <DataTable
                                table={historyTable}
                                columnCount={historyColumns.length}
                                emptyIcon={<Receipt />}
                                emptyMessage={
                                    hasActiveFilters
                                        ? 'No subscriptions match these filters'
                                        : 'No subscription history yet'
                                }
                                emptyDescription={
                                    hasActiveFilters
                                        ? 'Clear the search or status filter to see the full history.'
                                        : 'Trials, activations, and rejections all show up here once businesses start subscribing.'
                                }
                            />
                        </DataTableCardTable>

                        <DataTableCardFooter>
                            <DataTablePaginationServer paginated={history} />
                        </DataTableCardFooter>
                    </DataTableCard>
                </div>
            </div>

            <Dialog
                open={dialog !== null}
                onOpenChange={(open) => !open && closeDialog()}
            >
                <DialogContent>
                    {dialog?.mode === 'approve' ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Activate {dialog.request.businessName}?
                                </DialogTitle>
                                <DialogDescription>
                                    Confirms the {dialog.request.planName} plan
                                    ({dialog.request.referenceCode}). The
                                    business gets full access immediately and is
                                    notified by email.
                                </DialogDescription>
                            </DialogHeader>
                            <Field>
                                <FieldLabel htmlFor="decision-note">
                                    Note
                                    <span className="ml-1 font-normal text-muted-foreground">
                                        (optional)
                                    </span>
                                </FieldLabel>
                                <Textarea
                                    id="decision-note"
                                    value={note}
                                    onChange={(event) =>
                                        setNote(event.target.value)
                                    }
                                    placeholder="e.g. Matched CIB transfer of 05/08"
                                    rows={2}
                                />
                                <FieldDescription>
                                    Only visible to super admins.
                                </FieldDescription>
                            </Field>
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={closeDialog}
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    onClick={submitDecision}
                                    disabled={processing}
                                >
                                    {processing
                                        ? 'Activating…'
                                        : 'Activate subscription'}
                                </Button>
                            </DialogFooter>
                        </>
                    ) : dialog?.mode === 'reject' ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Reject {dialog.request.businessName}’s
                                    request?
                                </DialogTitle>
                                <DialogDescription>
                                    The business sees this reason on its
                                    subscription page and can submit a new
                                    request.
                                </DialogDescription>
                            </DialogHeader>
                            <Field>
                                <FieldLabel htmlFor="decision-note">
                                    Reason
                                </FieldLabel>
                                <Textarea
                                    id="decision-note"
                                    value={note}
                                    onChange={(event) =>
                                        setNote(event.target.value)
                                    }
                                    placeholder="e.g. No transfer with this reference reached our account"
                                    rows={2}
                                />
                            </Field>
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={closeDialog}
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    variant="destructive"
                                    onClick={submitDecision}
                                    disabled={processing || !note.trim()}
                                >
                                    {processing
                                        ? 'Rejecting…'
                                        : 'Reject request'}
                                </Button>
                            </DialogFooter>
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>
        </SuperAdminLayout>
    );
}
