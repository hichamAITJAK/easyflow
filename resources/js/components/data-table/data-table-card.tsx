import type { ReactNode } from 'react';
import { Card, CardFooter } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Single Card container for a backend-driven DataTable: filters, then a
 * search/actions toolbar, then the table itself, then a pagination footer —
 * each section separated by an internal divider instead of floating as
 * separate cards. Built on the shared Card primitive so it shares its ring,
 * radius, and --card-spacing token with every other card in the app.
 */
export function DataTableCard({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <Card
            className={cn(
                'gap-0 divide-y divide-border rounded-xl border border-border bg-card py-0 shadow-xs ring-0 dark:ring-0',
                className,
            )}
        >
            {children}
        </Card>
    );
}

export function DataTableCardFilters({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-wrap items-end gap-3 p-4', className)}>
            {children}
        </div>
    );
}

export function DataTableCardToolbar({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center justify-between gap-2 p-4',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function DataTableCardTable({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    // Pages that draw a plain <Table> here (settlements, commissions) get
    // the same skin as DataTable: plain header with small muted titles,
    // 16px cells and 24px outer gutters. Full-width colSpan cells (empty
    // states) keep their own padding.
    return (
        <div
            className={cn(
                'overflow-x-auto',
                '[&_thead]:bg-transparent [&_thead_tr:hover]:bg-transparent',
                '[&_th]:h-auto [&_th]:px-4 [&_th]:py-3 [&_th]:text-xs [&_th]:font-medium [&_th]:text-muted-foreground [&_th:first-child]:pl-6 [&_th:last-child]:pr-6',
                '[&_td:not([colspan])]:px-4 [&_td:not([colspan])]:py-4 [&_td:not([colspan]):first-child]:pl-6 [&_td:not([colspan]):last-child]:pr-6',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function DataTableCardFooter({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <CardFooter
            className={cn(
                'justify-between px-6 py-3 text-xs text-muted-foreground',
                className,
            )}
        >
            {children}
        </CardFooter>
    );
}
