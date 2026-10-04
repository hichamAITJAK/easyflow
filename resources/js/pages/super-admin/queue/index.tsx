import { Head, router } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { CircleCheck, RotateCcw, Trash2 } from 'lucide-react';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useTableFilters } from '@/hooks/use-table-filters';
import { useTranslation } from '@/hooks/use-translation';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import { cn } from '@/lib/utils';
import {
    flush as flushFailed,
    forget as forgetJob,
    index as queueIndex,
    retry as retryJob,
    retryAll as retryAllFailed,
} from '@/routes/super-admin/queue';
import type {
    FailedJob,
    Paginated,
    QueueDepth,
    QueueFilters,
    QueueMetrics,
} from '@/types';
import { createColumns } from './columns';

const ANY_QUEUE = 'all';

const FILTER_KEYS: (keyof QueueFilters)[] = ['search', 'queue'];

/**
 * Queue lag reads as a duration, not a raw second count — "18m" answers
 * "are we behind?" at a glance where "1080" does not.
 */
function formatLag(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h`;
    }

    return `${Math.floor(seconds / 86400)}d`;
}

/**
 * Lag is the one metric with a health reading: a queue nobody is draining
 * climbs forever, so past a few minutes it stops being neutral information.
 */
function lagTone(seconds: number | null): string {
    if (seconds === null || seconds < 300) {
        return '';
    }

    return seconds < 1800
        ? 'text-amber-600 dark:text-amber-400'
        : 'text-destructive';
}

function Stat({
    label,
    value,
    hint,
    className,
}: {
    label: string;
    value: string | number;
    hint?: string;
    className?: string;
}) {
    return (
        <Card className="py-4">
            <CardHeader className="gap-1 px-4">
                <CardDescription>{label}</CardDescription>
                <CardTitle className={cn('text-2xl tabular-nums', className)}>
                    {value}
                </CardTitle>
                {hint && (
                    <span className="text-xs text-muted-foreground">
                        {hint}
                    </span>
                )}
            </CardHeader>
        </Card>
    );
}

export default function SuperAdminQueueIndex({
    metrics,
    queues,
    failed,
    filters,
    failedQueues,
    connection,
}: {
    metrics: QueueMetrics;
    queues: QueueDepth[];
    failed: Paginated<FailedJob>;
    filters: QueueFilters;
    failedQueues: string[];
    connection: string;
}) {
    const { t } = useTranslation();

    const routeUrl = queueIndex().url;

    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters(routeUrl, filters, FILTER_KEYS);

    const [search, setSearch] = useState(filters.search ?? '');
    const debouncedSearch = useDebouncedValue(search, 300);
    const isFirstSearchRun = useRef(true);

    useEffect(() => {
        if (isFirstSearchRun.current) {
            isFirstSearchRun.current = false;

            return;
        }

        updateFilters({
            search: debouncedSearch || undefined,
            page: undefined,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    const [inspecting, setInspecting] = useState<FailedJob | null>(null);
    const [forgetting, setForgetting] = useState<FailedJob | null>(null);
    const [flushing, setFlushing] = useState(false);

    const columns = useMemo(
        () =>
            createColumns({
                t,
                onInspect: setInspecting,
                onForget: setForgetting,
                onRetry: (job) =>
                    router.post(
                        retryJob(job.uuid),
                        {},
                        { preserveScroll: true, preserveState: true },
                    ),
            }),
        [],
    );

    const table = useReactTable({
        data: failed.data,
        columns,
        getCoreRowModel: getCoreRowModel(),
    });

    const handleReset = () => {
        setSearch('');
        resetFilters();
    };

    return (
        <SuperAdminLayout>
            <Head title={t('Queue')} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title={t('Queue')}
                        description={t(
                            'Background job health across the platform.',
                        )}
                    />
                    <Badge variant="outline" className="font-mono text-xs">
                        {connection}
                    </Badge>
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat
                        label={t('Waiting')}
                        value={metrics.pending}
                        hint={t('Not yet picked up')}
                    />
                    <Stat
                        label={t('In progress')}
                        value={metrics.reserved}
                        hint={t('Reserved by a worker')}
                    />
                    <Stat
                        label={t('Oldest wait')}
                        value={formatLag(metrics.oldestPendingSeconds)}
                        hint={t('How far behind workers are')}
                        className={lagTone(metrics.oldestPendingSeconds)}
                    />
                    <Stat
                        label={t('Failed')}
                        value={metrics.failed}
                        hint={t(':count in the last 24h', {
                            count: metrics.failedLastDay,
                        })}
                        className={
                            metrics.failed > 0 ? 'text-destructive' : undefined
                        }
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('Queues')}</CardTitle>
                        <CardDescription>
                            {t(
                                "Depth per queue, so one backed-up queue doesn't hide behind a healthy total.",
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        {queues.length === 0 ? (
                            <p className="px-6 text-sm text-muted-foreground">
                                {t('Nothing queued right now.')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-6">
                                            {t('Queue')}
                                        </TableHead>
                                        <TableHead>{t('Waiting')}</TableHead>
                                        <TableHead>{t('Total')}</TableHead>
                                        <TableHead className="pr-6">
                                            {t('Oldest wait')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {queues.map((queue) => (
                                        <TableRow key={queue.queue}>
                                            <TableCell className="pl-6 font-mono text-xs">
                                                {queue.queue}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {queue.pending}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {queue.total}
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    'pr-6 tabular-nums',
                                                    lagTone(
                                                        queue.oldestPendingSeconds,
                                                    ),
                                                )}
                                            >
                                                {formatLag(
                                                    queue.oldestPendingSeconds,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <div className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Heading
                            variant="small"
                            title={t('Failed jobs')}
                            description={t(
                                'Jobs that exhausted their attempts. Retrying pushes them back onto their original queue.',
                            )}
                        />

                        {failed.total > 0 && (
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            retryAllFailed(),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <RotateCcw />
                                    {t('Retry all')}
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setFlushing(true)}
                                >
                                    <Trash2 />
                                    {t('Delete all')}
                                </Button>
                            </div>
                        )}
                    </div>

                    <DataTableCard>
                        <DataTableCardToolbar>
                            <div className="flex flex-wrap items-center gap-2">
                                <Input
                                    className="w-full max-w-sm sm:w-64"
                                    placeholder={t('Search job or error…')}
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    aria-label={t('Search failed jobs')}
                                />
                                <Select
                                    value={draft.queue ?? ANY_QUEUE}
                                    onValueChange={(value) =>
                                        updateFilters({
                                            queue:
                                                value === ANY_QUEUE
                                                    ? undefined
                                                    : value,
                                            page: undefined,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        className="w-40"
                                        aria-label={t('Filter by queue')}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY_QUEUE}>
                                            {t('Any queue')}
                                        </SelectItem>
                                        {failedQueues.map((queue) => (
                                            <SelectItem
                                                key={queue}
                                                value={queue}
                                            >
                                                {queue}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {hasActiveFilters && (
                                    <DataTableResetFiltersButton
                                        onReset={handleReset}
                                    />
                                )}
                            </div>

                            <div className="flex items-center gap-2">
                                <DataTablePerPageSelect
                                    routeUrl={routeUrl}
                                    query={filters}
                                    value={Number(filters.per_page ?? 20)}
                                    size="sm"
                                />
                                <DataTableViewOptions table={table} />
                            </div>
                        </DataTableCardToolbar>

                        <DataTableCardTable>
                            <DataTable
                                table={table}
                                columnCount={columns.length}
                                emptyIcon={<CircleCheck />}
                                emptyMessage={
                                    hasActiveFilters
                                        ? t(
                                              'No failed jobs match these filters',
                                          )
                                        : t('No failed jobs')
                                }
                                emptyDescription={
                                    hasActiveFilters
                                        ? t(
                                              'Clear the search or queue filter to see every failure.',
                                          )
                                        : 'Every job that has run so far either succeeded or is still being retried.'
                                }
                            />
                        </DataTableCardTable>

                        <DataTableCardFooter>
                            <DataTablePaginationServer paginated={failed} />
                        </DataTableCardFooter>
                    </DataTableCard>
                </div>
            </div>

            <Dialog
                open={inspecting !== null}
                onOpenChange={(open) => !open && setInspecting(null)}
            >
                <DialogContent className="max-w-3xl">
                    <DialogTitle>{inspecting?.jobName}</DialogTitle>
                    <DialogDescription className="font-mono text-xs">
                        {inspecting?.jobClass}
                    </DialogDescription>
                    <pre className="max-h-96 overflow-auto rounded-md bg-muted p-4 text-xs leading-relaxed whitespace-pre-wrap">
                        {inspecting?.exceptionFull}
                    </pre>
                </DialogContent>
            </Dialog>

            <Dialog
                open={forgetting !== null}
                onOpenChange={(open) => !open && setForgetting(null)}
            >
                <DialogContent>
                    <DialogTitle>Delete this failed job?</DialogTitle>
                    <DialogDescription>
                        {t(
                            'The job payload goes with it, so it can no longer be retried. Retry it instead if the failure might have been temporary.',
                        )}
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setForgetting(null)}
                        >
                            {t('Keep it')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (forgetting) {
                                    router.delete(forgetJob(forgetting.uuid), {
                                        preserveScroll: true,
                                        onSuccess: () => setForgetting(null),
                                    });
                                }
                            }}
                        >
                            {t('Delete job')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={flushing} onOpenChange={setFlushing}>
                <DialogContent>
                    <DialogTitle>Delete every failed job?</DialogTitle>
                    <DialogDescription>
                        {t(
                            'All :total failed jobs and their payloads are removed permanently. None of them can be retried afterwards.',
                            { total: failed.total },
                        )}
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => setFlushing(false)}
                        >
                            {t('Keep them')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(flushFailed(), {
                                    preserveScroll: true,
                                    onSuccess: () => setFlushing(false),
                                })
                            }
                        >
                            {t('Delete all')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SuperAdminLayout>
    );
}
