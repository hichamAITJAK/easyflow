import type { ColumnDef } from '@tanstack/react-table';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import { DataTableColumnHeader } from '@/components/data-table/data-table-column-header';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { formatCalendarDate, formatNumber } from '@/lib/format';
import type { Translator } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type CashRow = {
    id: number;
    name: string;
    /** Courier name for an account, phone for a delivery guy. */
    subtitle: string | null;
    image: string | null;
    shipped: number;
    delivered: number;
    returned: number;
    inTransit: number;
    deliveryRatePct: number | null;
    returnRatePct: number | null;
    collectedMad: number;
    deliveryCostMad: number;
    /** Running balance, independent of the period filter. */
    stillOwedMad: number;
    unsettledParcels: number;
    lastSettlement: {
        at: string;
        status: 'matched' | 'short';
        differenceMad: number;
    } | null;
};

function money(value: number): string {
    return formatNumber(Math.round(value * 100) / 100);
}

function initials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

/** Count with its share of the period's shipped parcels underneath. */
function CountWithRate({
    count,
    pct,
    tone,
}: {
    count: number;
    pct: number | null;
    tone?: 'success' | 'destructive';
}) {
    return (
        <div className="tabular-nums">
            <span className="font-semibold">{formatNumber(count)}</span>
            {pct !== null && (
                <span
                    className={cn(
                        'ml-1.5 text-xs',
                        tone === 'success' && 'text-success',
                        tone === 'destructive' && 'text-destructive',
                        !tone && 'text-muted-foreground',
                    )}
                >
                    {pct}%
                </span>
            )}
        </div>
    );
}

export function createColumns({
    t,
    kind,
}: {
    t: Translator;
    kind: 'couriers' | 'guys';
}): ColumnDef<CashRow>[] {
    return [
        {
            accessorKey: 'name',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title={t('Name')} />
            ),
            cell: ({ row }) => (
                <div className="flex items-center gap-3">
                    <Avatar className="size-9 rounded-lg border border-border bg-muted">
                        {row.original.image && (
                            <AvatarImage
                                src={row.original.image}
                                alt=""
                                className="object-contain p-1"
                            />
                        )}
                        <AvatarFallback className="rounded-lg text-xs font-semibold">
                            {initials(row.original.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <p className="truncate font-medium">
                            {row.original.name}
                        </p>
                        {row.original.subtitle && (
                            <p className="truncate text-xs text-muted-foreground">
                                {row.original.subtitle}
                            </p>
                        )}
                    </div>
                </div>
            ),
        },
        {
            accessorKey: 'shipped',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title={t('Shipped')} />
            ),
            cell: ({ row }) => (
                <div className="tabular-nums">
                    <span className="font-semibold">
                        {formatNumber(row.original.shipped)}
                    </span>
                    {row.original.inTransit > 0 && (
                        <span className="ml-1.5 text-xs text-muted-foreground">
                            {t(':count in transit', {
                                count: row.original.inTransit,
                            })}
                        </span>
                    )}
                </div>
            ),
        },
        {
            accessorKey: 'delivered',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title={t('Delivered')} />
            ),
            cell: ({ row }) => (
                <CountWithRate
                    count={row.original.delivered}
                    pct={row.original.deliveryRatePct}
                    tone="success"
                />
            ),
        },
        {
            accessorKey: 'returned',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title={t('Returned')} />
            ),
            cell: ({ row }) => (
                <CountWithRate
                    count={row.original.returned}
                    pct={row.original.returnRatePct}
                    tone={row.original.returned > 0 ? 'destructive' : undefined}
                />
            ),
        },
        {
            accessorKey: 'collectedMad',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title={t('Collected')} />
            ),
            cell: ({ row }) => (
                <span className="font-mono tabular-nums">
                    {money(row.original.collectedMad)}{' '}
                    <span className="text-xs text-muted-foreground">MAD</span>
                </span>
            ),
        },
        {
            accessorKey: 'deliveryCostMad',
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={kind === 'guys' ? t('Pay') : t('Delivery cost')}
                />
            ),
            cell: ({ row }) => (
                <span className="font-mono text-muted-foreground tabular-nums">
                    {money(row.original.deliveryCostMad)}{' '}
                    <span className="text-xs">MAD</span>
                </span>
            ),
        },
        {
            accessorKey: 'stillOwedMad',
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={t('Still owed')}
                />
            ),
            cell: ({ row }) => {
                const owed = row.original.stillOwedMad;

                return (
                    <div className="tabular-nums">
                        <span
                            className={cn(
                                'font-mono text-base font-semibold',
                                owed > 0 && 'text-destructive',
                                owed <= 0 && 'text-muted-foreground',
                            )}
                        >
                            {money(owed)}{' '}
                            <span className="text-xs font-normal">MAD</span>
                        </span>
                        {row.original.unsettledParcels > 0 && (
                            <p className="text-xs text-muted-foreground">
                                {row.original.unsettledParcels === 1
                                    ? t(':count parcel not settled', {
                                          count: row.original.unsettledParcels,
                                      })
                                    : t(':count parcels not settled', {
                                          count: row.original.unsettledParcels,
                                      })}
                            </p>
                        )}
                    </div>
                );
            },
        },
        {
            id: 'lastSettlement',
            accessorFn: (row) => row.lastSettlement?.at ?? '',
            header: ({ column }) => (
                <DataTableColumnHeader
                    column={column}
                    title={
                        kind === 'guys'
                            ? t('Last hand-over')
                            : t('Last settlement')
                    }
                />
            ),
            cell: ({ row }) => {
                const last = row.original.lastSettlement;

                if (!last) {
                    return (
                        <span className="text-sm text-muted-foreground">
                            {t('None yet')}
                        </span>
                    );
                }

                const short = last.status === 'short';

                return (
                    <div className="text-sm">
                        <p className="tabular-nums">
                            {formatCalendarDate(last.at)}
                        </p>
                        <p
                            className={cn(
                                'inline-flex items-center gap-0.5 text-xs',
                                short ? 'text-destructive' : 'text-success',
                            )}
                        >
                            {short ? (
                                <ArrowDownRight
                                    aria-hidden
                                    className="size-3.5"
                                />
                            ) : (
                                <ArrowUpRight
                                    aria-hidden
                                    className="size-3.5"
                                />
                            )}
                            {short
                                ? t(':amount MAD short', {
                                      amount: money(
                                          Math.abs(last.differenceMad),
                                      ),
                                  })
                                : t('Matched')}
                        </p>
                    </div>
                );
            },
        },
    ];
}
