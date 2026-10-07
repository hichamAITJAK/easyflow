import { router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
    Eye,
    FlaskConical,
    MoreHorizontal,
    Boxes,
    Package,
    Pencil,
    Trash2,
} from 'lucide-react';
import { markTest } from '@/actions/App/Http/Controllers/Products/ProductController';
import { DataTableColumnHeaderServer } from '@/components/data-table/data-table-column-header-server';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDate } from '@/lib/format';
import type { Translator } from '@/lib/i18n';
import type { Product, ProductFilters } from '@/types';

function toggleTest(product: Product) {
    router.patch(
        markTest.url(product.id),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

export function createColumns({
    t,
    filters,
    routeUrl,
    onEdit,
    onPreview,
    onDelete,
    onEditStock,
    canEditStock,
    canManage,
}: {
    t: Translator;
    filters: ProductFilters;
    routeUrl: string;
    onEdit: (product: Product) => void;
    onPreview: (product: Product) => void;
    onDelete: (product: Product) => void;
    onEditStock: (product: Product) => void;
    canEditStock: boolean;
    /** False for agents, whose catalogue access is read-only. */
    canManage: boolean;
}): ColumnDef<Product>[] {
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
            id: 'image',
            header: t('Image'),
            enableSorting: false,
            enableHiding: false,
            cell: ({ row }) => {
                const product = row.original;

                return (
                    <div className="flex size-10 items-center justify-center overflow-hidden rounded-md border bg-muted">
                        {product.thumbnail ? (
                            <img
                                src={product.thumbnail}
                                alt={product.name}
                                className="size-full object-cover"
                            />
                        ) : (
                            <Package className="size-5 text-muted-foreground" />
                        )}
                    </div>
                );
            },
        },
        {
            accessorKey: 'name',
            header: () => sortHeader(t('Name'), 'name'),
            cell: ({ row }) => (
                <span className="font-medium">{row.original.name}</span>
            ),
        },
        {
            id: 'store',
            accessorFn: (product) => product.store?.name ?? '—',
            header: t('Store'),
            cell: ({ row }) =>
                row.original.store?.name ?? (
                    <span className="text-muted-foreground">{t('Manual')}</span>
                ),
        },
        {
            accessorKey: 'sku',
            header: () => sortHeader(t('SKU'), 'sku'),
            cell: ({ row }) => (
                <span className="font-mono text-sm">
                    {row.original.sku ?? '—'}
                </span>
            ),
        },
        {
            accessorKey: 'price',
            header: () => sortHeader(t('Price'), 'price'),
            cell: ({ row }) => row.original.price ?? '—',
        },
        {
            id: 'inventory_quantity',
            header: t('Inventory'),
            cell: ({ row }) => {
                const product = row.original;
                const quantity =
                    product.variants_sum_inventory_quantity ??
                    product.inventory_quantity;

                return quantity ?? '—';
            },
        },
        {
            accessorKey: 'is_active',
            header: () => sortHeader(t('Status'), 'is_active'),
            cell: ({ row }) => (
                <div className="flex items-center gap-1.5">
                    <Badge
                        variant="outline"
                        className={
                            row.original.is_active
                                ? 'border-transparent bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300'
                                : undefined
                        }
                    >
                        {row.original.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                    {row.original.is_test && (
                        <Badge
                            variant="outline"
                            className="border-transparent bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300"
                        >
                            Test
                        </Badge>
                    )}
                </div>
            ),
        },
        {
            accessorKey: 'updated_at',
            header: () => sortHeader(t('Updated'), 'updated_at'),
            cell: ({ row }) => formatDate(row.original.updated_at),
        },
        {
            id: 'actions',
            enableSorting: false,
            enableHiding: false,
            cell: ({ row }) => {
                const product = row.original;

                return (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon">
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={() => onPreview(product)}
                            >
                                <Eye />
                                {t('Preview')}
                            </DropdownMenuItem>
                            {canEditStock && (
                                <DropdownMenuItem
                                    onSelect={() => onEditStock(product)}
                                >
                                    <Boxes />
                                    {t('Edit stock')}
                                </DropdownMenuItem>
                            )}
                            {canManage && (
                                <>
                                    <DropdownMenuItem
                                        onSelect={() => onEdit(product)}
                                    >
                                        <Pencil />
                                        {t('Edit')}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        onSelect={() => toggleTest(product)}
                                    >
                                        <FlaskConical />
                                        {product.is_test
                                            ? t('Unmark as test')
                                            : t('Mark as test')}
                                    </DropdownMenuItem>
                                    {product.store_id === null && (
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={() => onDelete(product)}
                                        >
                                            <Trash2 />
                                            {t('Delete')}
                                        </DropdownMenuItem>
                                    )}
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                );
            },
        },
    ];
}
