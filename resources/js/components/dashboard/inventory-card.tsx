import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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
import { useTranslation } from '@/hooks/use-translation';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as productsIndex } from '@/routes/products';

export type LowStockProduct = {
    id: number;
    name: string;
    sku: string | null;
    /** Product thumbnail URL; null falls back to initials. */
    image: string | null;
    /** Units left: the variants' total when the product has variants. */
    stock: number;
};

export type Inventory = {
    /** Products are listed once their stock drops below this. */
    threshold: number;
    /** Lowest stock first, capped server-side. */
    products: LowStockProduct[];
    /** All products under the threshold, including those not listed. */
    total: number;
};

/**
 * Products running low, lowest stock first, so the owner can reorder
 * before a confirmed order has nothing to ship.
 */
export function InventoryCard({ inventory }: { inventory: Inventory }) {
    const { t } = useTranslation();
    const { products, threshold, total } = inventory;
    const hidden = total - products.length;

    return (
        <Card className="shadow-none">
            <CardHeader className="flex flex-wrap items-start justify-between gap-2 space-y-0">
                <div className="grid gap-1.5">
                    <CardTitle>{t('Inventory')}</CardTitle>
                    <CardDescription>
                        {t('Products with fewer than :count in stock', {
                            count: threshold,
                        })}
                    </CardDescription>
                </div>
                <Link
                    href={productsIndex()}
                    prefetch
                    className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                >
                    {t('View all')}
                    <ArrowRight aria-hidden className="size-3.5" />
                </Link>
            </CardHeader>

            <CardContent>
                {products.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        {t('No product is running low.')}
                    </p>
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Product')}</TableHead>
                                    <TableHead className="text-right">
                                        {t('In stock')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {products.map((product) => {
                                    const out = product.stock <= 0;

                                    return (
                                        <TableRow key={product.id}>
                                            <TableCell className="font-medium">
                                                <span className="flex items-center gap-2.5">
                                                    <Avatar className="size-8 rounded-md">
                                                        {product.image && (
                                                            <AvatarImage
                                                                src={
                                                                    product.image
                                                                }
                                                                alt=""
                                                                className="object-cover"
                                                            />
                                                        )}
                                                        <AvatarFallback className="rounded-md text-xs">
                                                            {product.name
                                                                .slice(0, 2)
                                                                .toUpperCase()}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    <span className="grid min-w-0">
                                                        <span className="truncate">
                                                            {product.name}
                                                        </span>
                                                        {product.sku && (
                                                            <span className="truncate text-xs font-normal text-muted-foreground">
                                                                {product.sku}
                                                            </span>
                                                        )}
                                                    </span>
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <span className="inline-flex items-center justify-end gap-2">
                                                    {out && (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-destructive/30 bg-destructive/10 text-destructive"
                                                        >
                                                            {t('Out of stock')}
                                                        </Badge>
                                                    )}
                                                    <span
                                                        className={cn(
                                                            'font-semibold tabular-nums',
                                                            out &&
                                                                'text-destructive',
                                                        )}
                                                    >
                                                        {formatNumber(
                                                            product.stock,
                                                        )}
                                                    </span>
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                        {hidden > 0 && (
                            <p className="pt-3 text-xs text-muted-foreground">
                                {hidden === 1
                                    ? t(
                                          'And :count more product running low.',
                                          {
                                              count: hidden,
                                          },
                                      )
                                    : t(
                                          'And :count more products running low.',
                                          { count: hidden },
                                      )}
                            </p>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
