// See data-table.tsx: the `table` prop is incompatible with React Compiler's
// memoization since TanStack Table mutates state behind a stable reference.
'use no memo';

import type { Table } from '@tanstack/react-table';
import { Settings2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export function DataTableViewOptions<TData>({
    table,
}: {
    table: Table<TData>;
}) {
    const columns = table
        .getAllColumns()
        .filter(
            (column) =>
                typeof column.accessorFn !== 'undefined' && column.getCanHide(),
        );

    if (columns.length === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" className="ml-auto">
                    <Settings2 />
                    View
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuLabel>Toggle columns</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {columns.map((column) => (
                    <DropdownMenuCheckboxItem
                        key={column.id}
                        className="capitalize"
                        checked={column.getIsVisible()}
                        onCheckedChange={(value) =>
                            column.toggleVisibility(!!value)
                        }
                        onSelect={(event) => event.preventDefault()}
                    >
                        {column.id}
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
