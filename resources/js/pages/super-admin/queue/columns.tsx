import type { ColumnDef } from '@tanstack/react-table';
import { MoreHorizontal, RotateCcw, ScrollText, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDateTime } from '@/lib/format';
import type { Translator } from '@/lib/i18n';
import type { FailedJob } from '@/types';

export function createColumns({
    t,
    onInspect,
    onRetry,
    onForget,
}: {
    t: Translator;
    onInspect: (job: FailedJob) => void;
    onRetry: (job: FailedJob) => void;
    onForget: (job: FailedJob) => void;
}): ColumnDef<FailedJob>[] {
    return [
        {
            accessorKey: 'jobName',
            id: 'job',
            header: t('Job'),
            cell: ({ row }) => (
                <div className="grid gap-0.5">
                    <span className="font-medium">{row.original.jobName}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {row.original.queue}
                    </span>
                </div>
            ),
        },
        {
            accessorKey: 'exception',
            id: 'error',
            header: t('Error'),
            cell: ({ row }) => (
                <button
                    type="button"
                    onClick={() => onInspect(row.original)}
                    className="max-w-md truncate text-left text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    title={t('View full stack trace')}
                >
                    {row.original.exception ?? '—'}
                </button>
            ),
        },
        {
            accessorKey: 'connection',
            id: 'connection',
            header: t('Connection'),
            cell: ({ row }) => (
                <Badge variant="outline" className="font-mono text-xs">
                    {row.original.connection}
                </Badge>
            ),
        },
        {
            accessorKey: 'failedAt',
            id: 'failed at',
            header: t('Failed at'),
            cell: ({ row }) => (
                <span className="text-muted-foreground tabular-nums">
                    {formatDateTime(row.original.failedAt)}
                </span>
            ),
        },
        {
            id: 'actions',
            enableHiding: false,
            header: () => <span className="sr-only">{t('Actions')}</span>,
            cell: ({ row }) => (
                <div className="flex justify-end">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                aria-label={t('Actions for :name', {
                                    name: row.original.jobName,
                                })}
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={() => onInspect(row.original)}
                            >
                                <ScrollText />
                                {t('View stack trace')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => onRetry(row.original)}
                            >
                                <RotateCcw />
                                {t('Retry job')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => onForget(row.original)}
                            >
                                <Trash2 />
                                {t('Delete')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            ),
        },
    ];
}
