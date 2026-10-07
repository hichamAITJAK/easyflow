import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Products/ProductController';
import ProductInventoryController from '@/actions/App/Http/Controllers/Products/ProductInventoryController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { Product, ProductVariant } from '@/types';

/**
 * Edit the stock of one product: one field per variant, or a single
 * field when the product has none. Saving marks the product's stock as
 * managed in EasyFlow, so store syncs stop overwriting it.
 */
export function ProductStockDialog({
    open,
    onOpenChange,
    product,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    product: Product | null;
}) {
    const { t } = useTranslation();

    const [variants, setVariants] = useState<ProductVariant[]>([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [quantities, setQuantities] = useState<Record<string, string>>({});
    const [productQuantity, setProductQuantity] = useState('');

    useEffect(() => {
        if (!open || !product) {
            return;
        }

        setVariants([]);
        setQuantities({});
        setProductQuantity(
            product.inventory_quantity === null
                ? ''
                : String(product.inventory_quantity),
        );
        setLoading(true);

        fetch(ProductController.variants.url(product.id), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { variants: ProductVariant[] } | null) => {
                const list = data?.variants ?? [];
                setVariants(list);
                setQuantities(
                    Object.fromEntries(
                        list.map((variant) => [
                            String(variant.id),
                            variant.inventory_quantity === null
                                ? ''
                                : String(variant.inventory_quantity),
                        ]),
                    ),
                );
            })
            .catch(() => setVariants([]))
            .finally(() => setLoading(false));
    }, [open, product]);

    const toNumber = (value: string): number | null =>
        value.trim() === '' ? null : Math.max(0, parseInt(value, 10) || 0);

    const save = () => {
        if (!product) {
            return;
        }

        setSaving(true);

        const payload =
            variants.length > 0
                ? {
                      variants: Object.fromEntries(
                          Object.entries(quantities).map(([id, value]) => [
                              id,
                              toNumber(value),
                          ]),
                      ),
                  }
                : { inventory_quantity: toNumber(productQuantity) };

        router.patch(
            ProductInventoryController.update.url(product.id),
            payload,
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setSaving(false),
            },
        );
    };

    const label = (variant: ProductVariant) =>
        variant.options.length > 0
            ? variant.options.map((option) => option.value).join(' / ')
            : (variant.sku ?? `#${variant.id}`);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('Edit stock')}</DialogTitle>
                    <DialogDescription>
                        {product?.name}
                        {' · '}
                        {variants.length > 0
                            ? t('Set the quantity in stock for each variant.')
                            : t('Set the quantity in stock.')}
                    </DialogDescription>
                </DialogHeader>

                {loading ? (
                    <div className="flex items-center justify-center py-8 text-muted-foreground">
                        <Loader2 className="size-5 animate-spin" />
                    </div>
                ) : variants.length > 0 ? (
                    <div className="grid gap-3">
                        {variants.map((variant) => (
                            <div
                                key={variant.id}
                                className="flex items-center justify-between gap-3"
                            >
                                <Label
                                    htmlFor={`stock-${variant.id}`}
                                    className="min-w-0 flex-1 truncate font-normal"
                                >
                                    {label(variant)}
                                    {variant.sku &&
                                        variant.options.length > 0 && (
                                            <span className="ml-2 font-mono text-xs text-muted-foreground">
                                                {variant.sku}
                                            </span>
                                        )}
                                </Label>
                                <Input
                                    id={`stock-${variant.id}`}
                                    type="number"
                                    min="0"
                                    inputMode="numeric"
                                    className="w-24 text-right"
                                    value={quantities[String(variant.id)] ?? ''}
                                    onChange={(event) =>
                                        setQuantities((current) => ({
                                            ...current,
                                            [String(variant.id)]:
                                                event.target.value,
                                        }))
                                    }
                                />
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="stock-product">{t('Quantity')}</Label>
                        <Input
                            id="stock-product"
                            type="number"
                            min="0"
                            inputMode="numeric"
                            value={productQuantity}
                            onChange={(event) =>
                                setProductQuantity(event.target.value)
                            }
                        />
                    </div>
                )}

                {product?.stock_managed_locally && (
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Stock is managed in EasyFlow for this product; store syncs will not overwrite it.',
                        )}
                    </p>
                )}

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={save}
                        disabled={saving || loading}
                    >
                        {saving && <Spinner />}
                        {t('Save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
