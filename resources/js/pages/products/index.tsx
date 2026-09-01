import { Head, router, usePage } from '@inertiajs/react';
import { getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { CloudDownload, Plus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DataTable } from '@/components/data-table/data-table';
import {
    DataTableCard,
    DataTableCardFilters,
    DataTableCardFooter,
    DataTableCardTable,
    DataTableCardToolbar,
} from '@/components/data-table/data-table-card';
import { DataTablePaginationServer } from '@/components/data-table/data-table-pagination-server';
import { DataTablePerPageSelect } from '@/components/data-table/data-table-per-page-select';
import { DataTableResetFiltersButton } from '@/components/data-table/data-table-reset-filters-button';
import { DataTableViewOptions } from '@/components/data-table/data-table-view-options';
import Heading from '@/components/heading';
import { ProductDeleteDialog } from '@/components/products/product-delete-dialog';
import { ProductPreviewDialog } from '@/components/products/product-preview-dialog';
import { StoreSyncCommand } from '@/components/stores/store-sync-command';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiCombobox } from '@/components/ui/multi-combobox';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useTableFilters } from '@/hooks/use-table-filters';
import { dashboard } from '@/routes';
import {
    create as productsCreate,
    edit as productsEdit,
    index as productsIndex,
    sync as syncProducts,
} from '@/routes/products';
import type {
    Paginated,
    PageProps,
    Product,
    ProductFilters,
    ProductMetrics,
} from '@/types';
import { createColumns } from './columns';

type SimpleOption = { id: number; name: string };

// Mirrors the `manage-products` gate: agents read the catalogue, admins
// shape it. The routes enforce this — this only hides controls that would
// 403 on click.
const ADMIN_ROLES = ['admin', 'super_admin'];

const FILTER_KEYS: (keyof ProductFilters)[] = [
    'search',
    'store_ids',
    'price_min',
    'price_max',
];

export default function ProductsIndex({
    products,
    metrics,
    filters,
    stores,
}: {
    products: Paginated<Product>;
    metrics: ProductMetrics;
    filters: ProductFilters;
    stores: SimpleOption[];
}) {
    const { auth } = usePage<PageProps>().props;
    const canManage = ADMIN_ROLES.includes(auth.user.role);

    const [loading, setLoading] = useState(false);
    const [syncOpen, setSyncOpen] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewProduct, setPreviewProduct] = useState<Product | null>(
        null,
    );
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deletingProduct, setDeletingProduct] = useState<Product | null>(
        null,
    );

    const { draft, updateFilters, resetFilters, hasActiveFilters } =
        useTableFilters(productsIndex().url, filters, FILTER_KEYS);

    const selectedStores = draft.store_ids ? draft.store_ids.split(',') : [];

    const [search, setSearch] = useState(filters.search ?? '');
    const [priceMin, setPriceMin] = useState(filters.price_min ?? '');
    const [priceMax, setPriceMax] = useState(filters.price_max ?? '');
    const debouncedSearch = useDebouncedValue(search, 300);
    const debouncedPriceMin = useDebouncedValue(priceMin, 300);
    const debouncedPriceMax = useDebouncedValue(priceMax, 300);
    const isFirstRun = useRef(true);

    useEffect(() => {
        if (isFirstRun.current) {
            isFirstRun.current = false;

            return;
        }

        updateFilters({
            search: debouncedSearch || undefined,
            price_min: debouncedPriceMin || undefined,
            price_max: debouncedPriceMax || undefined,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch, debouncedPriceMin, debouncedPriceMax]);

    const handleReset = () => {
        setSearch('');
        setPriceMin('');
        setPriceMax('');
        resetFilters();
    };

    const loadProducts = (storeId: number | null) => {
        setLoading(true);
        router.post(
            syncProducts().url,
            // `platform: '*'` still means "every platform" — the store
            // picker narrows by store instead, and omitting store_id keeps
            // the original all-stores behaviour.
            { platform: '*', ...(storeId ? { store_id: storeId } : {}) },
            {
                preserveScroll: true,
                onFinish: () => setLoading(false),
            },
        );
    };

    const openCreate = () => {
        router.get(productsCreate().url);
    };

    const openEdit = (product: Product) => {
        router.get(productsEdit(product.id).url);
    };

    const openPreview = (product: Product) => {
        setPreviewProduct(product);
        setPreviewOpen(true);
    };

    const openDelete = (product: Product) => {
        setDeletingProduct(product);
        setDeleteOpen(true);
    };

    const columns = useMemo(
        () =>
            createColumns({
                filters,
                routeUrl: productsIndex().url,
                onEdit: openEdit,
                onPreview: openPreview,
                onDelete: openDelete,
                canManage,
            }),
        [filters, canManage],
    );

    const table = useReactTable({
        data: products.data,
        columns,
        getCoreRowModel: getCoreRowModel(),
    });

    return (
        <>
            <Head title="Products" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Products"
                        description="Products synced from your connected stores."
                    />
                    {canManage && (
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                className="px-3 sm:px-4"
                                onClick={openCreate}
                            >
                                <Plus />
                                <span className="hidden sm:inline">
                                    Add product
                                </span>
                            </Button>
                            <Button
                                className="px-3 sm:px-4"
                                onClick={() => setSyncOpen(true)}
                                disabled={loading}
                                // The label is hidden below `sm`, leaving an
                                // icon-only control with no accessible name.
                                aria-label="Load products"
                            >
                                <CloudDownload
                                    className={loading ? 'animate-spin' : ''}
                                />
                                <span className="hidden sm:inline">
                                    {loading
                                        ? 'Loading products…'
                                        : 'Load products'}
                                </span>
                            </Button>
                        </div>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Card className="py-4">
                        <CardHeader className="gap-1 px-4">
                            <CardDescription>Total</CardDescription>
                            <CardTitle className="text-2xl">
                                {metrics.total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="py-4">
                        <CardHeader className="gap-1 px-4">
                            <CardDescription>Active</CardDescription>
                            <CardTitle className="text-2xl text-emerald-600 dark:text-emerald-500">
                                {metrics.active}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="py-4">
                        <CardHeader className="gap-1 px-4">
                            <CardDescription>Inactive</CardDescription>
                            <CardTitle className="text-2xl text-destructive">
                                {metrics.inactive}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="py-4">
                        <CardHeader className="gap-1 px-4">
                            <CardDescription>Test</CardDescription>
                            <CardTitle className="text-2xl text-amber-600 dark:text-amber-500">
                                {metrics.test}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </div>

                <DataTableCard>
                    <DataTableCardFilters>
                        <div className="grid gap-1.5">
                            <Label htmlFor="products-store-filter">Store</Label>
                            <MultiCombobox
                                id="products-store-filter"
                                className="w-52"
                                options={stores.map((store) => ({
                                    value: String(store.id),
                                    label: store.name,
                                }))}
                                value={selectedStores}
                                onChange={(next) =>
                                    updateFilters({
                                        store_ids:
                                            next.length > 0
                                                ? next.join(',')
                                                : undefined,
                                    })
                                }
                                placeholder="All stores"
                                searchPlaceholder="Search stores…"
                                emptyMessage="No stores found."
                            />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="price_min">Min price</Label>
                            <Input
                                id="price_min"
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0"
                                className="w-32"
                                value={priceMin}
                                onChange={(event) =>
                                    setPriceMin(event.target.value)
                                }
                            />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="price_max">Max price</Label>
                            <Input
                                id="price_max"
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0"
                                className="w-32"
                                value={priceMax}
                                onChange={(event) =>
                                    setPriceMax(event.target.value)
                                }
                            />
                        </div>

                        {hasActiveFilters && (
                            <DataTableResetFiltersButton
                                onReset={handleReset}
                            />
                        )}
                    </DataTableCardFilters>

                    <DataTableCardToolbar>
                        <Input
                            className="max-w-sm"
                            placeholder="Search products…"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        <DataTableViewOptions table={table} />
                    </DataTableCardToolbar>

                    <DataTableCardTable>
                        <DataTable
                            table={table}
                            columnCount={columns.length}
                            emptyMessage={
                                canManage
                                    ? 'No products yet. Click "Load products" to sync from your connected stores.'
                                    : 'No products yet. Your administrator adds them here once a store is synced.'
                            }
                        />
                    </DataTableCardTable>

                    <DataTableCardFooter>
                        <div className="flex w-full flex-wrap items-center justify-between gap-4">
                            <DataTablePerPageSelect
                                routeUrl={productsIndex().url}
                                query={filters}
                                value={Number(filters.per_page ?? 20)}
                            />
                            <DataTablePaginationServer paginated={products} />
                        </div>
                    </DataTableCardFooter>
                </DataTableCard>
            </div>

            <ProductPreviewDialog
                open={previewOpen}
                onOpenChange={setPreviewOpen}
                product={previewProduct}
            />

            <ProductDeleteDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                product={deletingProduct}
            />

            <StoreSyncCommand
                open={syncOpen}
                onOpenChange={setSyncOpen}
                stores={stores}
                onSelect={loadProducts}
                noun="products"
            />
        </>
    );
}

ProductsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Products',
            href: '/products',
        },
    ],
};
