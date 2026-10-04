import type { ColumnDef } from '@tanstack/react-table';
import { MessageCircle, Phone, Star } from 'lucide-react';
import { DataTableColumnHeaderServer } from '@/components/data-table/data-table-column-header-server';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/format';
import type { Translator } from '@/lib/i18n';
import { formatMoroccoPhoneForWhatsApp } from '@/lib/phone';
import type { Customer, CustomerFilters } from '@/types';

export function createColumns({
    t,
    filters,
    routeUrl,
}: {
    t: Translator;
    filters: CustomerFilters;
    routeUrl: string;
}): ColumnDef<Customer>[] {
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
            header: () => sortHeader(t('Name'), 'name'),
            cell: ({ row }) => (
                <div className="flex items-center gap-2">
                    <span className="font-medium">{row.original.name}</span>
                    {row.original.is_best_customer && (
                        <Star className="size-3.5 fill-amber-400 text-amber-400" />
                    )}
                </div>
            ),
        },
        {
            accessorKey: 'phone',
            header: t('Phone'),
            cell: ({ row }) => {
                const phone = row.original.phone;
                const whatsapp = formatMoroccoPhoneForWhatsApp(phone);

                return (
                    <div className="flex items-center gap-2">
                        <span>{phone}</span>
                        {whatsapp && (
                            <a
                                href={`https://wa.me/${whatsapp}`}
                                target="_blank"
                                rel="noreferrer"
                                className="text-muted-foreground hover:text-foreground"
                                onClick={(event) => event.stopPropagation()}
                            >
                                <MessageCircle className="size-3.5" />
                            </a>
                        )}
                        <a
                            href={`tel:${phone}`}
                            className="text-muted-foreground hover:text-foreground"
                            onClick={(event) => event.stopPropagation()}
                        >
                            <Phone className="size-3.5" />
                        </a>
                    </div>
                );
            },
        },
        {
            accessorKey: 'city',
            header: () => sortHeader(t('City'), 'city'),
            cell: ({ row }) => row.original.city ?? '—',
        },
        {
            accessorKey: 'orders_count',
            header: () => sortHeader(t('Orders'), 'orders_count'),
            cell: ({ row }) => row.original.orders_count,
        },
        {
            accessorKey: 'delivered_orders_count',
            header: () => sortHeader(t('Delivered'), 'delivered_orders_count'),
            cell: ({ row }) => (
                <span className="text-emerald-600 dark:text-emerald-500">
                    {row.original.delivered_orders_count}
                </span>
            ),
        },
        {
            accessorKey: 'returned_orders_count',
            header: () => sortHeader(t('Returned'), 'returned_orders_count'),
            cell: ({ row }) => (
                <span className="text-destructive">
                    {row.original.returned_orders_count}
                </span>
            ),
        },
        {
            id: 'status',
            header: t('Status'),
            cell: ({ row }) =>
                row.original.is_blacklisted ? (
                    <Badge variant="destructive">{t('Blacklisted')}</Badge>
                ) : row.original.is_best_customer ? (
                    <Badge
                        variant="outline"
                        className="border-transparent bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300"
                    >
                        Best client
                    </Badge>
                ) : (
                    <Badge variant="outline">{t('Regular')}</Badge>
                ),
        },
        {
            accessorKey: 'last_order_at',
            header: () => sortHeader(t('Last order'), 'last_order_at'),
            cell: ({ row }) =>
                row.original.last_order_at
                    ? formatDate(row.original.last_order_at)
                    : '—',
        },
    ];
}
