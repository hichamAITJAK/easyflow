import type { ColumnDef } from '@tanstack/react-table';
import { MoreHorizontal, Users } from 'lucide-react';
import { DataTableColumnHeaderServer } from '@/components/data-table/data-table-column-header-server';
import {
    BusinessRowActionItems,
    businessRowActions,
} from '@/components/super-admin/business-row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDate } from '@/lib/format';
import type { Translator } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Business, BusinessFilters, BusinessStatus } from '@/types';

/**
 * A business's lifecycle state carries real operational meaning to a super
 * admin — suspended and cancelled tenants are the ones that need attention —
 * so each status keeps a distinct, stable color rather than one neutral chip.
 */
const STATUS_STYLES: Record<BusinessStatus, string> = {
    active: 'bg-[#16A08E]/10 text-[#0C7D6F]',
    suspended: 'bg-[#EFA22C]/12 text-[#A87110]',
    cancelled: 'bg-muted text-muted-foreground',
};

const STATUS_LABELS: Record<BusinessStatus, string> = {
    active: 'Active',
    suspended: 'Suspended',
    cancelled: 'Cancelled',
};

export function createColumns({
    t,
    filters,
    routeUrl,
    onView,
    onEdit,
    onSuspend,
    onReactivate,
    onCancel,
}: {
    t: Translator;
    filters: BusinessFilters;
    routeUrl: string;
    onView: (business: Business) => void;
    onEdit: (business: Business) => void;
    onSuspend: (business: Business) => void;
    onReactivate: (business: Business) => void;
    onCancel: (business: Business) => void;
}): ColumnDef<Business>[] {
    const sortHeader = (title: string, sortKey: string) => (
        <DataTableColumnHeaderServer
            title={title}
            sortKey={sortKey}
            currentSort={filters.sort}
            currentDirection={filters.direction}
            routeUrl={routeUrl}
            query={filters}
            className={cn(
                'ml-0 h-8 px-0 text-xs font-medium hover:bg-transparent hover:text-foreground [&_svg]:size-3.5',
                filters.sort === sortKey
                    ? 'text-foreground [&_svg]:opacity-90'
                    : 'text-muted-foreground [&_svg]:opacity-40',
            )}
        />
    );

    return [
        {
            accessorKey: 'name',
            id: 'business',
            header: () => sortHeader('Business', 'name'),
            cell: ({ row }) => (
                <div className="grid gap-0.5">
                    <span className="font-semibold">{row.original.name}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {row.original.slug}
                    </span>
                </div>
            ),
        },
        {
            accessorKey: 'status',
            header: () => sortHeader('Status', 'status'),
            cell: ({ row }) => (
                <Badge
                    variant="outline"
                    className={cn(
                        'rounded-full border-0 px-2.5 py-1 text-xs font-semibold',
                        STATUS_STYLES[row.original.status],
                    )}
                >
                    {t(STATUS_LABELS[row.original.status])}
                </Badge>
            ),
        },
        {
            accessorKey: 'users_count',
            // `id` doubles as the label in the column-visibility menu, so it
            // reads as a word rather than the raw `users_count` column name.
            id: 'users',
            header: () => sortHeader('Users', 'users_count'),
            cell: ({ row }) => (
                <span className="inline-flex items-center gap-1.5 font-mono text-sm tabular-nums">
                    <Users className="size-4 text-muted-foreground" />
                    {row.original.users_count ?? 0}
                </span>
            ),
        },
        {
            accessorKey: 'created_at',
            id: 'onboarded',
            header: () => sortHeader('Onboarded', 'created_at'),
            cell: ({ row }) => (
                <span className="text-sm text-muted-foreground">
                    {formatDate(row.original.created_at)}
                </span>
            ),
        },
        {
            id: 'actions',
            enableHiding: false,
            header: () => <span className="sr-only">{t('Actions')}</span>,
            cell: ({ row }) => {
                const business = row.original;
                const actions = businessRowActions({
                    t,
                    business,
                    onView,
                    onEdit,
                    onSuspend,
                    onReactivate,
                    onCancel,
                });

                return (
                    <div className="flex justify-end">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8 rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                                    aria-label={t('Actions for :name', {
                                        name: business.name,
                                    })}
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="end"
                                className="w-44 rounded-md border-border bg-popover shadow-md"
                            >
                                <BusinessRowActionItems
                                    actions={actions}
                                    Item={DropdownMenuItem}
                                />
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                );
            },
        },
    ];
}
