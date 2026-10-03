import type { Errors } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import InputError from '@/components/input-error';
import { AddressAutocomplete } from '@/components/ui/address-autocomplete';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
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
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import orderRoutes from '@/routes/orders';
import type { Order, OrderItem, OrderSource } from '@/types';

type ProductComboboxOption = { value: string; label: string };

type VariantOption = {
    id: number;
    sku: string | null;
    price: string | null;
    is_available: boolean;
    /** Null when the variant does not track stock — not the same as zero. */
    inventory_quantity: number | null;
    options: { name: string; value: string }[];
};

type ProductOption = {
    id: number;
    name: string;
    price: string | null;
    /** Used only when the product has no variants. */
    inventory_quantity: number | null;
    variants: VariantOption[];
};

/**
 * One variant of a line's product, with the quantity ordered of that variant.
 *
 * A customer ordering two smalls and a medium is buying one product in two
 * sizes, not two unrelated products, so a line holds a row per variant rather
 * than forcing a separate line each time. Each row still becomes its own
 * entry in the submitted `items` array — the payload shape is unchanged.
 */
type VariantLine = {
    key: string;
    product_variant_id: number;
    quantity: number;
    unit_price: string;
};

type LineItem = {
    key: string;
    product_id: number | null;
    product_name: string;
    /** Empty for a product with no variants, which uses the fields below. */
    variantLines: VariantLine[];
    /** Used only when the product has no variants to split across. */
    quantity: number;
    unit_price: string;
};

const ORDER_SOURCES: { value: OrderSource; label: string }[] = [
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'phone_call', label: 'Phone call' },
    { value: 'other', label: 'Other' },
];

function newLineItem(): LineItem {
    return {
        key: crypto.randomUUID(),
        product_id: null,
        product_name: '',
        variantLines: [],
        quantity: 1,
        unit_price: '0',
    };
}

function variantLabel(variant: VariantOption): string {
    return variant.options.map((option) => option.value).join(' / ');
}

/**
 * Regroups a saved order's flat items back into one line per product — the
 * inverse of the flattening done at submit, so reopening an order shows the
 * same shape that was entered rather than three separate T-Shirt lines.
 *
 * Grouping is by product_id, and only for items that carry a variant. Items
 * with no product_id (free-typed names) stay on their own line, since there
 * is no identity to group them by.
 */
function groupOrderItems(orderItems: OrderItem[]): LineItem[] {
    const lines: LineItem[] = [];
    const byProduct = new Map<number, LineItem>();

    for (const item of orderItems) {
        if (item.product_id === null || item.product_variant_id === null) {
            lines.push({
                key: crypto.randomUUID(),
                product_id: item.product_id,
                product_name: item.product_name_snapshot,
                variantLines: [],
                quantity: item.quantity,
                unit_price: item.unit_price,
            });

            continue;
        }

        const variantLine: VariantLine = {
            key: crypto.randomUUID(),
            product_variant_id: item.product_variant_id,
            quantity: item.quantity,
            unit_price: item.unit_price,
        };

        const existing = byProduct.get(item.product_id);

        if (existing) {
            existing.variantLines.push(variantLine);

            continue;
        }

        const line: LineItem = {
            key: crypto.randomUUID(),
            product_id: item.product_id,
            product_name: item.product_name_snapshot,
            variantLines: [variantLine],
            quantity: 1,
            unit_price: item.unit_price,
        };

        byProduct.set(item.product_id, line);
        lines.push(line);
    }

    return lines;
}

/**
 * Stock cap for one variant row, or for a variant-less product's own line.
 *
 * Null means unbounded — stock simply is not tracked — which is no reason to
 * stop an agent recording an order the customer just placed over the phone.
 */
function stockLimitFor(
    product: ProductOption | undefined,
    variantId: number | null,
): number | null {
    if (!product) {
        return null;
    }

    if (variantId === null) {
        return product.variants.length > 0 ? null : product.inventory_quantity;
    }

    return (
        product.variants.find((variant) => variant.id === variantId)
            ?.inventory_quantity ?? null
    );
}

/** One entry of the submitted `items` array. */
type ItemPayload = {
    product_id: number | null;
    product_variant_id: number | null;
    product_name: string;
    quantity: number;
    unit_price: string;
};

/** Clamps to the stock cap when there is one, with a floor of 1. */
function clampQuantity(quantity: number, limit: number | null): number {
    const atLeastOne = Math.max(1, quantity);

    if (limit === null) {
        return atLeastOne;
    }

    // A limit of 0 would floor to 1 and silently allow an out-of-stock line;
    // the variant is disabled in the picker for that case instead.
    return limit <= 0 ? atLeastOne : Math.min(atLeastOne, limit);
}

export function OrderFormDialog({
    open,
    onOpenChange,
    order,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** When set, the dialog edits this order instead of creating a new one. */
    order?: Order | null;
}) {
    const { t } = useTranslation();

    const isEditing = order != null;
    const [address, setAddress] = useState('');
    const [city, setCity] = useState('');
    const [customerName, setCustomerName] = useState('');
    const [customerPhone, setCustomerPhone] = useState('');
    const [orderSource, setOrderSource] = useState<string>('');
    const [products, setProducts] = useState<ProductOption[]>([]);
    const [loadingProducts, setLoadingProducts] = useState(false);
    const [items, setItems] = useState<LineItem[]>([]);
    const [totalAmount, setTotalAmount] = useState('0');
    const [notes, setNotes] = useState('');
    const [totalManuallyEdited, setTotalManuallyEdited] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [openProductPopoverKey, setOpenProductPopoverKey] = useState<
        string | null
    >(null);

    const productOptions: ProductComboboxOption[] = useMemo(
        () =>
            products.map((product) => ({
                value: String(product.id),
                label: product.name,
            })),
        [products],
    );

    // A line with variant rows is worth the sum of those rows; only a
    // variant-less line falls back to its own quantity and price.
    const computedTotal = useMemo(
        () =>
            items.reduce((sum, item) => {
                if (item.variantLines.length > 0) {
                    return (
                        sum +
                        item.variantLines.reduce(
                            (lineSum, line) =>
                                lineSum +
                                line.quantity *
                                    (parseFloat(line.unit_price) || 0),
                            0,
                        )
                    );
                }

                return sum + item.quantity * (parseFloat(item.unit_price) || 0);
            }, 0),
        [items],
    );

    // Keep the total in sync with the line items until the agent types into
    // it directly — same auto-fill-then-editable pattern as the city field
    // below, which stops following the address once hand-edited.
    useEffect(() => {
        if (!totalManuallyEdited) {
            setTotalAmount(computedTotal.toFixed(2));
        }
    }, [computedTotal, totalManuallyEdited]);

    useEffect(() => {
        if (!open) {
            return;
        }

        setAddress(order?.customer_address ?? '');
        setCity(order?.customer_city ?? '');
        setCustomerName(order?.customer_name ?? '');
        setCustomerPhone(order?.customer_phone ?? '');
        setOrderSource(
            order && ORDER_SOURCES.some((s) => s.value === order.source_platform)
                ? order.source_platform
                : '',
        );
        setNotes(order?.notes ?? '');
        setItems(groupOrderItems(order?.items ?? []));
        setTotalAmount(order?.total_amount ?? '0');
        // Editing an existing order starts from its already-settled total,
        // not one recomputed from items — same reasoning createManualOrder
        // trusts the caller's total rather than silently recalculating it.
        setTotalManuallyEdited(isEditing);
        setErrors({});

        setLoadingProducts(true);

        fetch(orderRoutes.products().url, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { products: ProductOption[] } | null) => {
                setProducts(data?.products ?? []);
            })
            .catch(() => setProducts([]))
            .finally(() => setLoadingProducts(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, order]);

    const addLineItem = () => {
        setItems((current) => [...current, newLineItem()]);
    };

    const removeLineItem = (key: string) => {
        setItems((current) => current.filter((item) => item.key !== key));
    };

    const updateLineItem = (key: string, changes: Partial<LineItem>) => {
        setItems((current) =>
            current.map((item) =>
                item.key === key ? { ...item, ...changes } : item,
            ),
        );
    };

    /** First variant not already on the line, so each row is a distinct one. */
    const firstUnusedVariant = (
        product: ProductOption,
        taken: VariantLine[],
    ): VariantOption | undefined =>
        product.variants.find(
            (variant) =>
                variant.is_available &&
                (variant.inventory_quantity === null ||
                    variant.inventory_quantity > 0) &&
                !taken.some((line) => line.product_variant_id === variant.id),
        );

    const selectProductForLine = (key: string, productIdValue: string) => {
        const product = products.find((p) => String(p.id) === productIdValue);
        const current = items.find((item) => item.key === key);

        // A variant product opens with its first orderable variant already
        // on the line: picking a product then being shown an empty variant
        // list reads as a broken step, and one variant is the common case.
        const opening =
            product && product.variants.length > 0
                ? firstUnusedVariant(product, [])
                : undefined;

        updateLineItem(key, {
            product_id: product?.id ?? null,
            product_name: product?.name ?? '',
            variantLines: opening
                ? [
                      {
                          key: crypto.randomUUID(),
                          product_variant_id: opening.id,
                          quantity: 1,
                          unit_price: opening.price ?? product?.price ?? '0',
                      },
                  ]
                : [],
            unit_price: product?.price ?? '0',
            quantity: clampQuantity(
                current?.quantity ?? 1,
                stockLimitFor(product, null),
            ),
        });
    };

    const addVariantLine = (key: string) => {
        const item = items.find((entry) => entry.key === key);
        const product = products.find((p) => p.id === item?.product_id);

        if (!item || !product) {
            return;
        }

        const next = firstUnusedVariant(product, item.variantLines);

        if (!next) {
            return;
        }

        updateLineItem(key, {
            variantLines: [
                ...item.variantLines,
                {
                    key: crypto.randomUUID(),
                    product_variant_id: next.id,
                    quantity: 1,
                    unit_price: next.price ?? product.price ?? '0',
                },
            ],
        });
    };

    const updateVariantLine = (
        key: string,
        variantLineKey: string,
        changes: Partial<VariantLine>,
    ) => {
        const item = items.find((entry) => entry.key === key);

        if (!item) {
            return;
        }

        updateLineItem(key, {
            variantLines: item.variantLines.map((line) =>
                line.key === variantLineKey ? { ...line, ...changes } : line,
            ),
        });
    };

    /**
     * Switching a row to a different variant re-prices and re-clamps it: each
     * variant carries its own price and stock, so a quantity that was legal a
     * moment ago may not be (pick 10, then switch to the size with 3 left).
     */
    const changeVariantForLine = (
        key: string,
        variantLineKey: string,
        variantIdValue: string,
    ) => {
        const item = items.find((entry) => entry.key === key);
        const product = products.find((p) => p.id === item?.product_id);
        const variant = product?.variants.find(
            (v) => String(v.id) === variantIdValue,
        );
        const line = item?.variantLines.find((l) => l.key === variantLineKey);

        if (!variant || !line) {
            return;
        }

        updateVariantLine(key, variantLineKey, {
            product_variant_id: variant.id,
            unit_price: variant.price ?? product?.price ?? '0',
            quantity: clampQuantity(
                line.quantity,
                stockLimitFor(product, variant.id),
            ),
        });
    };

    const removeVariantLine = (key: string, variantLineKey: string) => {
        const item = items.find((entry) => entry.key === key);

        if (!item) {
            return;
        }

        updateLineItem(key, {
            variantLines: item.variantLines.filter(
                (line) => line.key !== variantLineKey,
            ),
        });
    };

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});

        const payload = {
            source_platform: orderSource || null,
            customer_name: customerName,
            customer_phone: customerPhone,
            customer_address: address,
            customer_city: city || null,
            total_amount: totalAmount,
            notes: notes.trim() || null,
            // Each variant row is flattened into its own entry: the API takes
            // a flat list keyed by variant, so one product in three sizes is
            // three items sharing a product_id. The payload shape is exactly
            // what it was before variants could be split.
            items: items
                .filter((item) => item.product_name.trim() !== '')
                .flatMap<ItemPayload>((item) =>
                    item.variantLines.length > 0
                        ? item.variantLines.map((line) => ({
                              product_id: item.product_id,
                              product_variant_id: line.product_variant_id,
                              product_name: item.product_name,
                              quantity: line.quantity,
                              unit_price: line.unit_price,
                          }))
                        : [
                              {
                                  product_id: item.product_id,
                                  product_variant_id: null,
                                  product_name: item.product_name,
                                  quantity: item.quantity,
                                  unit_price: item.unit_price,
                              },
                          ],
                ),
        };

        const submit = isEditing
            ? (options: Parameters<typeof router.post>[2]) =>
                  router.patch(
                      OrderController.update.url(order.id),
                      payload,
                      options,
                  )
            : (options: Parameters<typeof router.post>[2]) =>
                  router.post(OrderController.store.url(), payload, options);

        submit({
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (formErrors) => setErrors(formErrors),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEditing ? t('Edit order') : t('Add order')}
                    </DialogTitle>
                    <DialogDescription>
                        {isEditing
                            ? "Update this order's customer details and items."
                            : t('Manually record a customer order.')}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="customer_name">{t('Customer name')}</Label>
                            <Input
                                id="customer_name"
                                value={customerName}
                                onChange={(event) =>
                                    setCustomerName(event.target.value)
                                }
                                required
                                placeholder={t('Jane Doe')}
                            />
                            <InputError message={errors.customer_name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="customer_phone">{t('Phone')}</Label>
                            <Input
                                id="customer_phone"
                                value={customerPhone}
                                onChange={(event) =>
                                    setCustomerPhone(event.target.value)
                                }
                                required
                                placeholder="+212 6 00 00 00 00"
                            />
                            <InputError message={errors.customer_phone} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="customer_address">{t('Address')}</Label>
                        <AddressAutocomplete
                            id="customer_address"
                            name="customer_address"
                            value={address}
                            onChange={setAddress}
                            onResolved={(resolved) => {
                                if (resolved.city) {
                                    setCity(resolved.city);
                                }
                            }}
                            required
                        />
                        <InputError message={errors.customer_address} />
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="customer_city">{t('City')}</Label>
                            <Input
                                id="customer_city"
                                value={city}
                                onChange={(event) =>
                                    setCity(event.target.value)
                                }
                            />
                            <InputError message={errors.customer_city} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="order_source">{t('Source')}</Label>
                            <Select
                                value={orderSource}
                                onValueChange={setOrderSource}
                            >
                                <SelectTrigger
                                    id="order_source"
                                    className="w-full"
                                >
                                    <SelectValue placeholder={t('Not specified')} />
                                </SelectTrigger>
                                <SelectContent>
                                    {ORDER_SOURCES.map((source) => (
                                        <SelectItem
                                            key={source.value}
                                            value={source.value}
                                        >
                                            {t(source.label)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.source_platform} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="total_amount">{t('Total amount')}</Label>
                            <Input
                                id="total_amount"
                                type="number"
                                step="0.01"
                                min="0"
                                value={totalAmount}
                                onChange={(event) => {
                                    setTotalAmount(event.target.value);
                                    setTotalManuallyEdited(true);
                                }}
                                required
                            />
                            <InputError message={errors.total_amount} />
                        </div>
                    </div>

                    {/* Annotates the whole order rather than any one line.
                        This is the note an agent writes during the call —
                        "ring the bell twice", "confirm the colour before
                        shipping" — and it rides along to fulfilment. */}
                    <div className="grid gap-2">
                        <Label htmlFor="order_notes">{t('Note')}</Label>
                        <Textarea
                            id="order_notes"
                            value={notes}
                            onChange={(event) => setNotes(event.target.value)}
                            maxLength={2000}
                            rows={3}
                            placeholder={t('Anything the warehouse or the next agent should know…')}
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <div className="space-y-3 rounded-lg border p-4">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium">{t('Products')}</p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addLineItem}
                            >
                                <Plus />
                                {t('Add product')}
                            </Button>
                        </div>

                        {items.map((item) => {
                            const selectedProduct = products.find(
                                (p) => p.id === item.product_id,
                            );
                            const hasVariants =
                                (selectedProduct?.variants.length ?? 0) > 0;
                            const plainStockLimit = stockLimitFor(
                                selectedProduct,
                                null,
                            );
                            const canAddVariant =
                                selectedProduct !== undefined &&
                                firstUnusedVariant(
                                    selectedProduct,
                                    item.variantLines,
                                ) !== undefined;
                            const lineTotal = hasVariants
                                ? item.variantLines.reduce(
                                      (sum, line) =>
                                          sum +
                                          line.quantity *
                                              (parseFloat(line.unit_price) || 0),
                                      0,
                                  )
                                : item.quantity *
                                  (parseFloat(item.unit_price) || 0);

                            return (
                                <div
                                    key={item.key}
                                    className="space-y-2 rounded-md border p-3"
                                >
                                    <div className="flex items-end gap-2">
                                        <div className="grid flex-1 gap-2">
                                            <Label>{t('Product')}</Label>
                                            <Popover
                                                open={
                                                    openProductPopoverKey ===
                                                    item.key
                                                }
                                                onOpenChange={(nextOpen) =>
                                                    setOpenProductPopoverKey(
                                                        nextOpen
                                                            ? item.key
                                                            : null,
                                                    )
                                                }
                                            >
                                                <PopoverTrigger asChild>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        role="combobox"
                                                        aria-expanded={
                                                            openProductPopoverKey ===
                                                            item.key
                                                        }
                                                        disabled={
                                                            loadingProducts
                                                        }
                                                        className="w-full justify-between font-normal"
                                                    >
                                                        {item.product_id
                                                            ? (productOptions.find(
                                                                  (option) =>
                                                                      option.value ===
                                                                      String(
                                                                          item.product_id,
                                                                      ),
                                                              )?.label ??
                                                              item.product_name)
                                                            : item.product_name
                                                              ? item.product_name
                                                              : loadingProducts
                                                                ? t('Loading products…')
                                                                : t('Select a product')}
                                                        <ChevronsUpDown className="opacity-50" />
                                                    </Button>
                                                </PopoverTrigger>
                                                <PopoverContent className="w-(--radix-popover-trigger-width) p-0">
                                                    <Command>
                                                        <CommandInput placeholder={t('Search products…')} />
                                                        <CommandList>
                                                            <CommandEmpty>
                                                                {t('No results found.')}
                                                            </CommandEmpty>
                                                            <CommandGroup>
                                                                {productOptions.map(
                                                                    (
                                                                        option,
                                                                    ) => (
                                                                        <CommandItem
                                                                            key={
                                                                                option.value
                                                                            }
                                                                            value={
                                                                                option.label
                                                                            }
                                                                            onSelect={() => {
                                                                                selectProductForLine(
                                                                                    item.key,
                                                                                    option.value,
                                                                                );
                                                                                setOpenProductPopoverKey(
                                                                                    null,
                                                                                );
                                                                            }}
                                                                        >
                                                                            <Check
                                                                                className={cn(
                                                                                    'mr-2',
                                                                                    String(
                                                                                        item.product_id ??
                                                                                            '',
                                                                                    ) ===
                                                                                        option.value
                                                                                        ? 'opacity-100'
                                                                                        : 'opacity-0',
                                                                                )}
                                                                            />
                                                                            {
                                                                                option.label
                                                                            }
                                                                        </CommandItem>
                                                                    ),
                                                                )}
                                                            </CommandGroup>
                                                        </CommandList>
                                                    </Command>
                                                </PopoverContent>
                                            </Popover>
                                        </div>

                                        {/* Quantity and price live on the
                                            variant rows when there are any —
                                            a line split across three sizes has
                                            no single quantity to show here. */}
                                        {!hasVariants && (
                                            <>
                                                <div className="grid w-20 gap-2">
                                                    <Label>{t('Qty')}</Label>
                                                    <Input
                                                        type="number"
                                                        min="1"
                                                        max={
                                                            plainStockLimit ??
                                                            undefined
                                                        }
                                                        value={item.quantity}
                                                        onChange={(event) =>
                                                            updateLineItem(
                                                                item.key,
                                                                {
                                                                    quantity:
                                                                        clampQuantity(
                                                                            parseInt(
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                                10,
                                                                            ) ||
                                                                                1,
                                                                            plainStockLimit,
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    />
                                                </div>

                                                <div className="grid w-28 gap-2">
                                                    <Label>{t('Unit price')}</Label>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        value={item.unit_price}
                                                        onChange={(event) =>
                                                            updateLineItem(
                                                                item.key,
                                                                {
                                                                    unit_price:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </>
                                        )}

                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                removeLineItem(item.key)
                                            }
                                            aria-label={t('Remove product')}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>

                                    {/* One row per variant ordered. The same
                                        product in three sizes is three rows
                                        here and three entries in the payload,
                                        but one line on screen — which is how
                                        the customer described it on the call. */}
                                    {hasVariants && (
                                        <div className="space-y-2 border-t pt-3">
                                            {item.variantLines.map((line) => {
                                                const rowLimit = stockLimitFor(
                                                    selectedProduct,
                                                    line.product_variant_id,
                                                );
                                                const atLimit =
                                                    rowLimit !== null &&
                                                    line.quantity >= rowLimit;

                                                return (
                                                    <div
                                                        key={line.key}
                                                        className="flex items-end gap-2"
                                                    >
                                                        <div className="grid flex-1 gap-2">
                                                            <Label>
                                                                {t('Variant')}
                                                            </Label>
                                                            <Select
                                                                value={String(
                                                                    line.product_variant_id,
                                                                )}
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    changeVariantForLine(
                                                                        item.key,
                                                                        line.key,
                                                                        value,
                                                                    )
                                                                }
                                                            >
                                                                <SelectTrigger className="w-full">
                                                                    <SelectValue placeholder={t('Select a variant')} />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {selectedProduct!.variants.map(
                                                                        (
                                                                            variant,
                                                                        ) => {
                                                                            // Tracked stock at zero is as
                                                                            // unorderable as a disabled
                                                                            // variant, and the two read
                                                                            // differently to an agent:
                                                                            // "sold out" is a restock,
                                                                            // "unavailable" is a catalogue
                                                                            // decision.
                                                                            const soldOut =
                                                                                variant.inventory_quantity !==
                                                                                    null &&
                                                                                variant.inventory_quantity <=
                                                                                    0;
                                                                            // Already on another row of
                                                                            // this line — offering it
                                                                            // again would split one
                                                                            // variant across two rows.
                                                                            const taken =
                                                                                variant.id !==
                                                                                    line.product_variant_id &&
                                                                                item.variantLines.some(
                                                                                    (
                                                                                        other,
                                                                                    ) =>
                                                                                        other.product_variant_id ===
                                                                                        variant.id,
                                                                                );

                                                                            return (
                                                                                <SelectItem
                                                                                    key={
                                                                                        variant.id
                                                                                    }
                                                                                    value={String(
                                                                                        variant.id,
                                                                                    )}
                                                                                    disabled={
                                                                                        !variant.is_available ||
                                                                                        soldOut ||
                                                                                        taken
                                                                                    }
                                                                                >
                                                                                    {variantLabel(
                                                                                        variant,
                                                                                    )}
                                                                                    {!variant.is_available
                                                                                        ? ' (unavailable)'
                                                                                        : soldOut
                                                                                          ? t('(sold out)')
                                                                                          : taken
                                                                                            ? t('(already added)')
                                                                                            : ''}
                                                                                </SelectItem>
                                                                            );
                                                                        },
                                                                    )}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>

                                                        <div className="grid w-20 gap-2">
                                                            <Label>{t('Qty')}</Label>
                                                            <Input
                                                                type="number"
                                                                min="1"
                                                                max={
                                                                    rowLimit ??
                                                                    undefined
                                                                }
                                                                value={
                                                                    line.quantity
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateVariantLine(
                                                                        item.key,
                                                                        line.key,
                                                                        {
                                                                            quantity:
                                                                                clampQuantity(
                                                                                    parseInt(
                                                                                        event
                                                                                            .target
                                                                                            .value,
                                                                                        10,
                                                                                    ) ||
                                                                                        1,
                                                                                    rowLimit,
                                                                                ),
                                                                        },
                                                                    )
                                                                }
                                                                className={cn(
                                                                    atLimit &&
                                                                        'border-warning-text',
                                                                )}
                                                            />
                                                        </div>

                                                        <div className="grid w-28 gap-2">
                                                            <Label>
                                                                {t('Unit price')}
                                                            </Label>
                                                            <Input
                                                                type="number"
                                                                step="0.01"
                                                                min="0"
                                                                value={
                                                                    line.unit_price
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    updateVariantLine(
                                                                        item.key,
                                                                        line.key,
                                                                        {
                                                                            unit_price:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </div>

                                                        {/* The last row stays:
                                                            a variant product
                                                            with no variant is
                                                            not orderable, and
                                                            removing the whole
                                                            product is what the
                                                            line's own delete
                                                            is for. */}
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            disabled={
                                                                item
                                                                    .variantLines
                                                                    .length <= 1
                                                            }
                                                            onClick={() =>
                                                                removeVariantLine(
                                                                    item.key,
                                                                    line.key,
                                                                )
                                                            }
                                                            aria-label={t('Remove variant')}
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </div>
                                                );
                                            })}

                                            <div className="flex items-center justify-between gap-3 pt-1">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        addVariantLine(item.key)
                                                    }
                                                    disabled={!canAddVariant}
                                                >
                                                    <Plus />
                                                    {t('Add variant')}
                                                </Button>

                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {lineTotal.toFixed(2)} MAD
                                                </span>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {isEditing ? t('Save changes') : t('Create order')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
