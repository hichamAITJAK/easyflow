import {
    Box,
    Check,
    ExternalLink,
    Layers,
    Loader2,
    Package,
    Store,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Products/ProductController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Product, ProductVariant } from '@/types';

/**
 * Groups a flat variant list back into option name -> ordered unique values,
 * so the preview can render one swatch row per option (Size, Color, …)
 * rather than a flat list of full combinations.
 */
function optionGroups(variants: ProductVariant[]): Map<string, string[]> {
    const groups = new Map<string, string[]>();

    for (const variant of variants) {
        for (const option of variant.options) {
            const values = groups.get(option.name) ?? [];

            if (!values.includes(option.value)) {
                values.push(option.value);
            }

            groups.set(option.name, values);
        }
    }

    return groups;
}

export function ProductPreviewDialog({
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
    const [selected, setSelected] = useState<Record<string, string>>({});
    const [manualImageOverride, setManualImageOverride] = useState<
        string | null
    >(null);

    useEffect(() => {
        if (!open || !product) {
            return;
        }

        setVariants([]);
        setSelected({});
        setManualImageOverride(null);
        setLoading(true);

        fetch(ProductController.variants.url(product.id), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((data: { variants: ProductVariant[] }) => {
                setVariants(data.variants);

                // Auto-select first available variant options so the user sees a complete combination immediately
                if (data.variants.length > 0) {
                    const firstAvailable =
                        data.variants.find(
                            (v) => v.is_available && v.options.length > 0,
                        ) ?? data.variants.find((v) => v.options.length > 0);

                    if (firstAvailable && firstAvailable.options.length > 0) {
                        const initialSelected: Record<string, string> = {};

                        for (const opt of firstAvailable.options) {
                            initialSelected[opt.name] = opt.value;
                        }

                        setSelected(initialSelected);
                    }
                }
            })
            .finally(() => setLoading(false));
    }, [open, product]);

    if (!product) {
        return null;
    }

    const groups = optionGroups(variants);
    const hasVariants = groups.size > 0;

    const activeVariant = hasVariants
        ? variants.find((variant) =>
              variant.options.every(
                  (option) => selected[option.name] === option.value,
              ),
          )
        : undefined;

    const displayImage =
        manualImageOverride ?? activeVariant?.image ?? product.thumbnail;
    const displayPrice = activeVariant?.price ?? product.price;
    const displaySku = activeVariant?.sku ?? product.sku;
    const displayStock =
        activeVariant?.inventory_quantity ??
        product.variants_sum_inventory_quantity ??
        product.inventory_quantity;

    // Collect all unique images for the interactive gallery strip
    const galleryItems: {
        url: string;
        label: string;
        variant?: ProductVariant;
    }[] = [];
    const seenUrls = new Set<string>();

    if (product.thumbnail) {
        galleryItems.push({
            url: product.thumbnail,
            label: t('Main Thumbnail'),
        });
        seenUrls.add(product.thumbnail);
    }

    for (const variant of variants) {
        if (variant.image && !seenUrls.has(variant.image)) {
            seenUrls.add(variant.image);
            galleryItems.push({
                url: variant.image,
                label:
                    variant.options.map((o) => o.value).join(' / ') ||
                    'Variant',
                variant,
            });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col overflow-hidden border bg-card p-0 shadow-2xl sm:max-w-3xl md:max-w-4xl">
                {/* Header Bar */}
                <div className="grid shrink-0 grid-cols-3 items-center gap-3 border-b bg-muted/30 px-6 py-4 pr-14">
                    <div className="flex items-center gap-2.5 justify-self-start">
                        <DialogTitle className="text-base font-semibold tracking-tight text-foreground">
                            {t('Quick Preview')}
                        </DialogTitle>
                        <span className="font-mono text-xs text-muted-foreground">
                            #{product.id}
                        </span>
                    </div>

                    <div className="flex items-center gap-2 justify-self-center">
                        {product.store ? (
                            <Badge
                                variant="outline"
                                className="gap-1.5 border-blue-500/30 bg-blue-500/10 px-2.5 py-0.5 text-xs font-medium text-blue-600 dark:text-blue-400"
                            >
                                <Store className="size-3.5" />
                                {product.store.name}
                            </Badge>
                        ) : (
                            <Badge
                                variant="secondary"
                                className="gap-1.5 px-2.5 py-0.5 text-xs font-medium"
                            >
                                <Package className="size-3.5" />
                                {t('Manual')}
                            </Badge>
                        )}

                        <Badge
                            variant="outline"
                            className={cn(
                                'px-2.5 py-0.5 text-xs font-medium',
                                product.is_active
                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                    : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {product.is_active ? 'Active' : 'Draft'}
                        </Badge>

                        {product.is_test && (
                            <Badge
                                variant="outline"
                                className="border-amber-500/30 bg-amber-500/10 px-2.5 py-0.5 text-xs font-medium text-amber-600 dark:text-amber-400"
                            >
                                {t('Test Item')}
                            </Badge>
                        )}
                    </div>

                    <div aria-hidden="true" />
                </div>

                {/* Scrollable Body */}
                <div className="flex-1 overflow-y-auto p-6 sm:p-8">
                    <div className="grid items-start gap-8 md:grid-cols-[1.1fr_1fr]">
                        {/* LEFT COLUMN: HERO IMAGE & GALLERY STRIP */}
                        <div className="space-y-3">
                            <div className="group relative flex aspect-square w-full items-center justify-center overflow-hidden rounded-2xl border bg-gradient-to-b from-muted/50 to-muted/20 shadow-2xs">
                                {displayImage ? (
                                    <img
                                        src={displayImage}
                                        alt={product.name}
                                        className="size-full object-cover transition-transform duration-500 group-hover:scale-105"
                                    />
                                ) : (
                                    <div className="flex flex-col items-center justify-center gap-3 text-muted-foreground">
                                        <div className="flex size-16 items-center justify-center rounded-2xl bg-muted/60 shadow-inner">
                                            <Package className="size-8" />
                                        </div>
                                        <span className="text-xs font-medium">
                                            {t('No thumbnail uploaded')}
                                        </span>
                                    </div>
                                )}

                                {/* Image Badge Indicator */}
                                {displayImage && (
                                    <div className="pointer-events-none absolute top-3 left-3">
                                        <Badge
                                            variant="secondary"
                                            className="bg-background/90 px-2.5 py-1 text-[11px] font-medium shadow-2xs backdrop-blur-md"
                                        >
                                            {manualImageOverride
                                                ? t('Selected Preview')
                                                : activeVariant?.image
                                                  ? `Variant: ${activeVariant.options.map((o) => o.value).join(' / ')}`
                                                  : t('Main Product Thumbnail')}
                                        </Badge>
                                    </div>
                                )}
                            </div>

                            {/* Mini Thumbnail Gallery Strip (if multiple images exist) */}
                            {galleryItems.length > 1 && (
                                <div className="space-y-1.5">
                                    <span className="text-[11px] font-medium tracking-wider text-muted-foreground uppercase">
                                        {t(
                                            'Media Gallery (:galleryItemsCount)',
                                            {
                                                galleryItemsCount:
                                                    galleryItems.length,
                                            },
                                        )}
                                    </span>
                                    <div className="flex gap-2.5 overflow-x-auto pb-1">
                                        {galleryItems.map((item, idx) => {
                                            const isSelected =
                                                displayImage === item.url;

                                            return (
                                                <button
                                                    key={idx}
                                                    type="button"
                                                    onClick={() => {
                                                        setManualImageOverride(
                                                            item.url,
                                                        );

                                                        // If clicking a variant image, auto-switch swatches to match that variant
                                                        if (item.variant) {
                                                            const nextSelected: Record<
                                                                string,
                                                                string
                                                            > = {};

                                                            for (const opt of item
                                                                .variant
                                                                .options) {
                                                                nextSelected[
                                                                    opt.name
                                                                ] = opt.value;
                                                            }

                                                            setSelected(
                                                                nextSelected,
                                                            );
                                                        }
                                                    }}
                                                    title={item.label}
                                                    className={cn(
                                                        'relative size-16 shrink-0 overflow-hidden rounded-xl border-2 transition-all',
                                                        isSelected
                                                            ? 'scale-95 border-primary shadow-xs ring-2 ring-primary/20'
                                                            : 'border-border/60 opacity-70 hover:border-border hover:opacity-100',
                                                    )}
                                                >
                                                    <img
                                                        src={item.url}
                                                        alt={item.label}
                                                        className="size-full object-cover"
                                                    />
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* RIGHT COLUMN: DETAILS & SWATCHES */}
                        <div className="flex flex-col gap-5">
                            {/* Title & SKU */}
                            <div className="space-y-2">
                                <h2 className="text-2xl leading-snug font-bold tracking-tight text-foreground">
                                    {product.name}
                                </h2>

                                <div className="flex flex-wrap items-center gap-2">
                                    {displaySku ? (
                                        <Badge
                                            variant="outline"
                                            className="bg-muted/50 px-2.5 py-1 font-mono text-xs text-muted-foreground"
                                        >
                                            {t('SKU: :displaySku', {
                                                displaySku,
                                            })}
                                        </Badge>
                                    ) : (
                                        <Badge
                                            variant="outline"
                                            className="bg-muted/30 px-2.5 py-1 text-xs text-muted-foreground"
                                        >
                                            {t('No SKU assigned')}
                                        </Badge>
                                    )}

                                    {product.external_product_id && (
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {t(
                                                'Ext ID: # :external_product_id',
                                                {
                                                    external_product_id:
                                                        product.external_product_id,
                                                },
                                            )}
                                        </span>
                                    )}
                                </div>
                            </div>

                            {/* Price & Stock Highlight Box */}
                            <div className="space-y-3.5 rounded-2xl border bg-card/80 p-5 shadow-2xs">
                                <div className="flex items-baseline justify-between">
                                    <span className="text-3xl font-extrabold tracking-tight text-foreground">
                                        {displayPrice
                                            ? `$${displayPrice}`
                                            : '—'}
                                    </span>

                                    {displayStock !== null &&
                                    displayStock !== undefined ? (
                                        <div
                                            className={cn(
                                                'flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold',
                                                Number(displayStock) > 10
                                                    ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                    : Number(displayStock) > 0
                                                      ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                                      : 'bg-destructive/10 text-destructive',
                                            )}
                                        >
                                            <Box className="size-3.5" />
                                            {Number(displayStock) > 0
                                                ? `${displayStock} units available`
                                                : t('Out of stock')}
                                        </div>
                                    ) : (
                                        <div className="flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">
                                            <Box className="size-3.5" />
                                            {t('Stock not tracked')}
                                        </div>
                                    )}
                                </div>

                                {activeVariant && (
                                    <div className="flex items-center justify-between border-t pt-3 text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1.5">
                                            <Layers className="size-3.5 text-primary" />
                                            {t('Selected variant combination')}
                                        </span>
                                        <span className="font-medium text-foreground">
                                            {activeVariant.options
                                                .map((o) => o.value)
                                                .join(' • ')}
                                        </span>
                                    </div>
                                )}
                            </div>

                            {/* Variant Loading State */}
                            {loading && (
                                <div className="flex items-center gap-2 rounded-xl border bg-muted/30 p-4 text-xs text-muted-foreground">
                                    <Loader2 className="size-4 animate-spin text-primary" />
                                    {t('Loading product options and variants…')}
                                </div>
                            )}

                            {/* Option Swatches */}
                            {hasVariants && (
                                <div className="space-y-4 rounded-2xl border bg-muted/10 p-5 shadow-2xs">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-bold tracking-wider text-foreground uppercase">
                                            {t('Select Options')}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {groups.size}{' '}
                                            {groups.size === 1
                                                ? 'attribute'
                                                : 'attributes'}
                                        </span>
                                    </div>

                                    <div className="grid gap-4">
                                        {[...groups].map(([name, values]) => (
                                            <div
                                                key={name}
                                                className="space-y-2"
                                            >
                                                <div className="flex items-center justify-between text-xs">
                                                    <span className="font-semibold text-muted-foreground">
                                                        {name}
                                                    </span>
                                                    {selected[name] && (
                                                        <span className="font-medium text-foreground">
                                                            {selected[name]}
                                                        </span>
                                                    )}
                                                </div>

                                                <div className="flex flex-wrap gap-2">
                                                    {values.map((value) => {
                                                        const isSelected =
                                                            selected[name] ===
                                                            value;

                                                        return (
                                                            <button
                                                                key={value}
                                                                type="button"
                                                                onClick={() => {
                                                                    setManualImageOverride(
                                                                        null,
                                                                    );
                                                                    setSelected(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [name]:
                                                                                current[
                                                                                    name
                                                                                ] ===
                                                                                value
                                                                                    ? ''
                                                                                    : value,
                                                                        }),
                                                                    );
                                                                }}
                                                                className={cn(
                                                                    'flex items-center gap-1.5 rounded-xl border px-3.5 py-2 text-xs font-medium shadow-2xs transition-all',
                                                                    isSelected
                                                                        ? 'scale-[1.02] border-primary bg-primary text-primary-foreground shadow-sm'
                                                                        : 'border-border bg-card text-foreground hover:border-primary/50 hover:bg-muted',
                                                                )}
                                                            >
                                                                {isSelected && (
                                                                    <Check className="size-3.5 stroke-[2.5]" />
                                                                )}
                                                                {value}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Description */}
                            {product.description && (
                                <div className="space-y-2">
                                    <h4 className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
                                        {t('Description')}
                                    </h4>
                                    <div className="rounded-xl border bg-muted/20 p-4 text-sm leading-relaxed whitespace-pre-line text-foreground/90 shadow-2xs">
                                        {product.description}
                                    </div>
                                </div>
                            )}

                            {/* External Store Link Button */}
                            {product.public_url && (
                                <div className="pt-2">
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="w-full gap-2 shadow-2xs sm:w-auto"
                                    >
                                        <a
                                            href={product.public_url}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <ExternalLink className="size-4 text-muted-foreground" />
                                            {t('View Product on Store')}
                                        </a>
                                    </Button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
