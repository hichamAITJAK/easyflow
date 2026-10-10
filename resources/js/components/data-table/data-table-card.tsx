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
        <div
            className={cn(
                'flex flex-wrap items-end gap-3 p-(--card-spacing)',
                className,
            )}
        >
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
    return <div className={cn('overflow-x-auto', className)}>{children}</div>;
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
