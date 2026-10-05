import {
    ArrowDown,
    ArrowDownRight,
    ArrowUp,
    ArrowUpDown,
    ArrowUpRight,
} from 'lucide-react';
import { useState } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslation } from '@/hooks/use-translation';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

export type PerformanceRow = {
    name: string;
    /** Store logo / product thumbnail / courier logo URL; null falls back to an initial. */
    image: string | null;
    orders: number;
    /** Null for couriers — they only handle post-confirmation orders. */
    confirmationRate: number | null;
    deliveryRate: number;
    /** Counts behind the rates: confirmed of orders, delivered of submitted. */
    confirmed: number;
    submitted: number;
    delivered: number;
    /** Couriers only: mean shipped → delivered duration in days. */
    avgDeliveryDays?: number;
};

export type RateTargets = {
    confirmation: number;
    delivery: number;
};

type SortKey =
    | 'orders'
    | 'confirmed'
    | 'confirmationRate'
    | 'delivered'
    | 'deliveryRate'
    | 'avgDeliveryDays';

/**
 * Rate tinted relative to its business target — the same definition of
 * "good" the team-performance card teaches, not an arbitrary fixed band.
 * At/above target = success with an up glyph; more than 10% (relative)
 * below = destructive with a down glyph; the near-miss band in between
 * stays quiet. Glyph + sr-only text carry the meaning without color.
 */
function RateCell({ value, target }: { value: number | null; target: number }) {
    const { t } = useTranslation();

    if (value === null) {
        return (
            <TableCell className="text-right text-muted-foreground">
                —
            </TableCell>
        );
    }

    const above = value >= target;
    const wellBelow = value < target * 0.9;

    return (
        <TableCell className="text-right font-semibold tabular-nums">
            <span
                className={cn(
                    'inline-flex items-center justify-end gap-0.5',
                    above && 'text-success',
                    wellBelow && 'text-destructive',
                )}
            >
                {above && <ArrowUpRight aria-hidden className="size-3.5" />}
                {wellBelow && (
                    <ArrowDownRight aria-hidden className="size-3.5" />
                )}
                {value.toFixed(0)}%
                {above && (
                    <span className="sr-only">{t(', above target')}</span>
                )}
                {wellBelow && (
                    <span className="sr-only">{t(', well below target')}</span>
                )}
            </span>
        </TableCell>
    );
}

function SortableHead({
    label,
    column,
    sort,
    onSort,
}: {
    label: string;
    column: SortKey;
    sort: { key: SortKey; desc: boolean };
    onSort: (key: SortKey) => void;
}) {
    const { t } = useTranslation();
    const active = sort.key === column;
    const SortIcon = active ? (sort.desc ? ArrowDown : ArrowUp) : ArrowUpDown;

    return (
        <TableHead className="text-right">
            <Button
                variant="ghost"
                size="sm"
                className="-mr-2.5 gap-1"
                onClick={() => onSort(column)}
                aria-label={
                    t('Sort by :label', { label }) +
                    (active
                        ? sort.desc
                            ? t(', descending')
                            : t(', ascending')
                        : '')
                }
            >
                {label}
                <SortIcon
                    aria-hidden
                    className={cn(
                        'size-3.5',
                        !active && 'text-muted-foreground',
                    )}
                />
            </Button>
        </TableHead>
    );
}

function RowsTable({
    rows,
    targets,
    emptyMessage,
    hideConfirmation = false,
}: {
    rows: PerformanceRow[];
    targets: RateTargets;
    emptyMessage: string;
    /** Couriers tab: confirmation happens before the courier exists. */
    hideConfirmation?: boolean;
}) {
    const { t } = useTranslation();

    // "Confirmed" and "Delivered" are already translated as one order's
    // status. As column heads they count many orders, which other
    // languages word differently, so they get their own entries and fall
    // back to the plain English word when there is none.
    const countLabel = (label: string): string => {
        const key = `${label} (count)`;
        const translated = t(key);

        return translated === key ? label : translated;
    };

    const [sort, setSort] = useState<{ key: SortKey; desc: boolean }>({
        key: 'orders',
        desc: true,
    });

    if (rows.length === 0) {
        return (
            <div className="flex h-48 items-center justify-center text-sm text-muted-foreground">
                {emptyMessage}
            </div>
        );
    }

    const toggleSort = (key: SortKey) =>
        setSort((prev) =>
            prev.key === key ? { key, desc: !prev.desc } : { key, desc: true },
        );

    const ranked = [...rows].sort((a, b) => {
        const left = a[sort.key] ?? -1;
        const right = b[sort.key] ?? -1;

        return sort.desc ? right - left : left - right;
    });

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>{t('Name')}</TableHead>
                    <SortableHead
                        label={t('Orders')}
                        column="orders"
                        sort={sort}
                        onSort={toggleSort}
                    />
                    {!hideConfirmation && (
                        <>
                            <SortableHead
                                label={countLabel('Confirmed')}
                                column="confirmed"
                                sort={sort}
                                onSort={toggleSort}
                            />
                            <SortableHead
                                label={t('Confirmation %')}
                                column="confirmationRate"
                                sort={sort}
                                onSort={toggleSort}
                            />
                        </>
                    )}
                    <SortableHead
                        label={countLabel('Delivered')}
                        column="delivered"
                        sort={sort}
                        onSort={toggleSort}
                    />
                    <SortableHead
                        label={t('Delivery %')}
                        column="deliveryRate"
                        sort={sort}
                        onSort={toggleSort}
                    />
                    {hideConfirmation && (
                        <SortableHead
                            label={t('Avg days')}
                            column="avgDeliveryDays"
                            sort={sort}
                            onSort={toggleSort}
                        />
                    )}
                </TableRow>
            </TableHeader>
            <TableBody>
                {ranked.map((row) => (
                    <TableRow key={row.name}>
                        <TableCell className="font-medium">
                            <span className="flex items-center gap-2.5">
                                <Avatar className="size-8 rounded-md">
                                    {row.image && (
                                        <AvatarImage
                                            src={row.image}
                                            alt=""
                                            className="object-cover"
                                        />
                                    )}
                                    <AvatarFallback className="rounded-md text-xs">
                                        {row.name.slice(0, 2).toUpperCase()}
                                    </AvatarFallback>
                                </Avatar>
                                <span className="max-w-[220px] truncate">
                                    {row.name}
                                </span>
                            </span>
                        </TableCell>
                        <TableCell className="text-right font-semibold tabular-nums">
                            {formatNumber(row.orders)}
                        </TableCell>
                        {!hideConfirmation && (
                            <>
                                <TableCell className="text-right font-semibold tabular-nums">
                                    {formatNumber(row.confirmed)}
                                </TableCell>
                                <RateCell
                                    value={row.confirmationRate}
                                    target={targets.confirmation}
                                />
                            </>
                        )}
                        <TableCell className="text-right font-semibold tabular-nums">
                            {formatNumber(row.delivered)}
                        </TableCell>
                        <RateCell
                            value={row.deliveryRate}
                            target={targets.delivery}
                        />
                        {hideConfirmation && (
                            <TableCell className="text-right font-semibold tabular-nums">
                                {row.avgDeliveryDays !== undefined
                                    ? `${row.avgDeliveryDays.toFixed(1)}d`
                                    : '—'}
                            </TableCell>
                        )}
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * One ranked table for "what sells and how well": stores, products, or
 * couriers (tab toggle), sortable by any metric — default by volume, so
 * the top row is the winner. Confirmation and delivery success sit
 * alongside volume so "sells a lot" and "actually gets delivered" are
 * read together. The couriers tab drops the confirmation column (a
 * courier only ever sees confirmed orders).
 */
export function PerformanceTable({
    stores,
    products,
    couriers,
    targets,
}: {
    stores: PerformanceRow[];
    products: PerformanceRow[];
    couriers: PerformanceRow[];
    /** Business rate targets — tinting is relative to these. */
    targets: RateTargets;
}) {
    const { t } = useTranslation();

    return (
        <Card className="shadow-none">
            <CardHeader className="flex items-start justify-between gap-2 space-y-0">
                <div className="grid gap-1.5">
                    <CardTitle>
                        {t('Store, product & courier performance')}
                    </CardTitle>
                    <CardDescription>
                        {t(
                            'Ranked by orders this period — rates read against the :confirmation% confirmation and :delivery% delivery targets',
                            {
                                confirmation: targets.confirmation,
                                delivery: targets.delivery,
                            },
                        )}
                    </CardDescription>
                </div>
            </CardHeader>

            <CardContent>
                <Tabs defaultValue="stores">
                    <TabsList>
                        <TabsTrigger value="stores">{t('Stores')}</TabsTrigger>
                        <TabsTrigger value="products">
                            {t('Products')}
                        </TabsTrigger>
                        <TabsTrigger value="couriers">
                            {t('Couriers')}
                        </TabsTrigger>
                    </TabsList>
                    <TabsContent value="stores">
                        <RowsTable
                            rows={stores}
                            targets={targets}
                            emptyMessage={t(
                                'No store activity in this period yet.',
                            )}
                        />
                    </TabsContent>
                    <TabsContent value="products">
                        <RowsTable
                            rows={products}
                            targets={targets}
                            emptyMessage={t(
                                'No product activity in this period yet.',
                            )}
                        />
                    </TabsContent>
                    <TabsContent value="couriers">
                        <RowsTable
                            rows={couriers}
                            targets={targets}
                            emptyMessage={t('No shipments in this period yet.')}
                            hideConfirmation
                        />
                    </TabsContent>
                </Tabs>
            </CardContent>
        </Card>
    );
}
