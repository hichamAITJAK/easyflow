// See data-table.tsx: the `table` prop is incompatible with React Compiler's
// memoization since TanStack Table mutates state behind a stable reference.
'use no memo';

import type { Table } from '@tanstack/react-table';
import {
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export function DataTablePagination<TData>({ table }: { table: Table<TData> }) {
    const { pageIndex, pageSize } = table.getState().pagination;
    const pageCount = table.getPageCount();

    if (pageCount <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <span>Rows per page</span>
                <Select
                    value={String(pageSize)}
                    onValueChange={(value) => table.setPageSize(Number(value))}
                >
                    <SelectTrigger size="sm" className="w-18">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {[10, 20, 30, 50].map((size) => (
                            <SelectItem key={size} value={String(size)}>
                                {size}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="flex items-center gap-4">
                <span className="text-sm text-muted-foreground">
                    Page {pageIndex + 1} of {pageCount}
                </span>
                <div className="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-8"
                        onClick={() => table.firstPage()}
                        disabled={!table.getCanPreviousPage()}
                    >
                        <ChevronsLeft />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-8"
                        onClick={() => table.previousPage()}
                        disabled={!table.getCanPreviousPage()}
                    >
                        <ChevronLeft />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-8"
                        onClick={() => table.nextPage()}
                        disabled={!table.getCanNextPage()}
                    >
                        <ChevronRight />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-8"
                        onClick={() => table.lastPage()}
                        disabled={!table.getCanNextPage()}
                    >
                        <ChevronsRight />
                    </Button>
                </div>
            </div>
        </div>
    );
}
