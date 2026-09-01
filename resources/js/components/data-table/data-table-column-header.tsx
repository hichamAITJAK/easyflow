// The `column` object comes from a TanStack Table instance with a stable
// identity that mutates internally — incompatible with React Compiler's
// reference-equality memoization (see data-table.tsx for details).
'use no memo';

import type { Column } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function DataTableColumnHeader<TData, TValue>({
    column,
    title,
    className,
}: {
    column: Column<TData, TValue>;
    title: string;
    className?: string;
}) {
    if (!column.getCanSort()) {
        return <div className={className}>{title}</div>;
    }

    const sorted = column.getIsSorted();

    return (
        <Button
            variant="ghost"
            size="sm"
            className={cn('-ml-3 h-8', className)}
            onClick={() => column.toggleSorting(sorted === 'asc')}
        >
            {title}
            {sorted === 'asc' ? (
                <ArrowUp />
            ) : sorted === 'desc' ? (
                <ArrowDown />
            ) : (
                <ChevronsUpDown />
            )}
        </Button>
    );
}
