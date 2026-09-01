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
import { cn } from '@/lib/utils';
import type { Business, BusinessFilters, BusinessStatus } from '@/types';

/**
 * A business's lifecycle state carries real operational meaning to a super
 * admin — suspended and cancelled tenants are the ones that need attention —
 * so each status keeps a distinct, stable color rather than one neutral chip.
 */
const STATUS_STYLES: Record<BusinessStatus, string> = {
    active: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    suspended:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    cancelled: 'bg-muted text-muted-foreground',
};

const STATUS_LABELS: Record<BusinessStatus, string> = {
    active: 'Active',
    suspended: 'Suspended',
    cancelled: 'Cancelled',
};

export function createColumns({
    filters,
    routeUrl,
    onView,
    onEdit,
    onSuspend,
    onReactivate,
    onCancel,
}: {
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
        />
    );

    return [
        {
            accessorKey: 'name',
            id: 'business',
            header: () => sortHeader('Business', 'name'),
            cell: ({ row }) => (
                <div className="grid gap-0.5">
                    <span className="font-medium">{row.original.name}</span>
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
                        'text-xs font-medium',
                        STATUS_STYLES[row.original.status],
                    )}
                >
                    {STATUS_LABELS[row.original.status]}
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
                <span className="inline-flex items-center gap-1.5 tabular-nums">
                    <Users className="size-3.5 text-muted-foreground" />
                    {row.original.users_count ?? 0}
                </span>
            ),
        },
        {
            accessorKey: 'created_at',
            id: 'onboarded',
            header: () => sortHeader('Onboarded', 'created_at'),
            cell: ({ row }) => (
                <span className="text-muted-foreground">
                    {formatDate(row.original.created_at)}
                </span>
            ),
        },
        {
            id: 'actions',
            enableHiding: false,
            header: () => <span className="sr-only">Actions</span>,
            cell: ({ row }) => {
                const business = row.original;
                const actions = businessRowActions({
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
                                    className="size-8"
                                    aria-label={`Actions for ${business.name}`}
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
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
