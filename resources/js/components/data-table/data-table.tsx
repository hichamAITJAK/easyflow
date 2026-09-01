// TanStack Table's `table` instance keeps a stable object identity while
// mutating internally, which defeats React Compiler's reference-equality
// memoization and leaves this UI stuck on stale row/sort/filter state.
// Opt this component out of compilation so it always re-renders.
'use no memo';

import { flexRender } from '@tanstack/react-table';
import type { Row, Table as TanstackTable } from '@tanstack/react-table';
import { Inbox } from 'lucide-react';
import { Fragment  } from 'react';
import type {ReactElement, ReactNode} from 'react';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useInertiaNavigating } from '@/hooks/use-inertia-navigating';
import { cn } from '@/lib/utils';

export function DataTable<TData>({
    table,
    columnCount,
    emptyMessage = 'No results.',
    emptyDescription,
    emptyIcon = <Inbox />,
    renderRow = (_row, children) => children,
}: {
    table: TanstackTable<TData>;
    columnCount: number;
    emptyMessage?: string;
    emptyDescription?: ReactNode;
    emptyIcon?: ReactNode;
    /**
     * Wraps each rendered row's TableRow — e.g. in a ContextMenu — without
     * DataTable itself needing to know about any specific table's row
     * actions. DataTable keys the result itself, so the wrapper doesn't
     * need to worry about React's list-key requirement. Defaults to
     * rendering the row as-is.
     */
    renderRow?: (row: Row<TData>, children: ReactElement) => ReactElement;
}) {
    const navigating = useInertiaNavigating();

    return (
        <Table>
            <TableHeader>
                {table.getHeaderGroups().map((headerGroup) => (
                    <TableRow key={headerGroup.id}>
                        {headerGroup.headers.map((header) => (
                            <TableHead key={header.id}>
                                {header.isPlaceholder
                                    ? null
                                    : flexRender(
                                          header.column.columnDef.header,
                                          header.getContext(),
                                      )}
                            </TableHead>
                        ))}
                    </TableRow>
                ))}
            </TableHeader>
            <TableBody
                aria-busy={navigating}
                className={cn(
                    'transition-opacity',
                    navigating && 'pointer-events-none opacity-50',
                )}
            >
                {table.getRowModel().rows.length ? (
                    table.getRowModel().rows.map((row) => (
                        <Fragment key={row.id}>
                            {renderRow(
                                row,
                                <TableRow
                                    data-state={
                                        row.getIsSelected() && 'selected'
                                    }
                                >
                                    {row.getVisibleCells().map((cell) => (
                                        <TableCell key={cell.id}>
                                            {flexRender(
                                                cell.column.columnDef.cell,
                                                cell.getContext(),
                                            )}
                                        </TableCell>
                                    ))}
                                </TableRow>,
                            )}
                        </Fragment>
                    ))
                ) : (
                    <TableRow className="hover:bg-transparent">
                        <TableCell colSpan={columnCount} className="p-0">
                            <Empty className="border-none py-12">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        {emptyIcon}
                                    </EmptyMedia>
                                    <EmptyTitle>{emptyMessage}</EmptyTitle>
                                    {emptyDescription && (
                                        <EmptyDescription>
                                            {emptyDescription}
                                        </EmptyDescription>
                                    )}
                                </EmptyHeader>
                            </Empty>
                        </TableCell>
                    </TableRow>
                )}
            </TableBody>
        </Table>
    );
}
