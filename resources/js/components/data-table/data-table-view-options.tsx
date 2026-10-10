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
import { useTranslation } from '@/hooks/use-translation';

export function DataTableViewOptions<TData>({
    table,
}: {
    table: Table<TData>;
}) {
    const { t } = useTranslation();
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
                <Button
                    variant="outline"
                    size="sm"
                    className="ml-auto h-9 gap-2 rounded-md bg-card px-3 text-sm font-medium shadow-xs hover:bg-accent hover:text-accent-foreground"
                >
                    <Settings2 />
                    {t('View')}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="w-44 rounded-md border-border bg-popover p-1 shadow-md"
            >
                <DropdownMenuLabel>{t('Toggle columns')}</DropdownMenuLabel>
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
