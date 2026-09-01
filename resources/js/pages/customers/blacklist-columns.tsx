import type { ColumnDef } from '@tanstack/react-table';
import { DataTableColumnHeaderServer } from '@/components/data-table/data-table-column-header-server';
import { formatDateTime } from '@/lib/format';
import type { CustomerBlacklistEntry, CustomerBlacklistFilters } from '@/types';

export function createBlacklistColumns({
    filters,
    routeUrl,
}: {
    filters: CustomerBlacklistFilters;
    routeUrl: string;
}): ColumnDef<CustomerBlacklistEntry>[] {
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
            accessorKey: 'phone_encrypted',
            header: 'Phone',
            cell: ({ row }) => (
                <span className="font-medium">
                    {row.original.phone_encrypted}
                </span>
            ),
        },
        {
            accessorKey: 'reason',
            header: 'Reason',
            cell: ({ row }) => row.original.reason ?? '—',
        },
        {
            accessorKey: 'notes',
            header: 'Notes',
            cell: ({ row }) => (
                <span className="line-clamp-1 max-w-xs text-muted-foreground">
                    {row.original.notes ?? '—'}
                </span>
            ),
        },
        {
            id: 'added_by',
            header: 'Added by',
            cell: ({ row }) => row.original.added_by_user?.name ?? '—',
        },
        {
            accessorKey: 'created_at',
            header: () => sortHeader('Added on', 'created_at'),
            cell: ({ row }) => formatDateTime(row.original.created_at),
        },
    ];
}
