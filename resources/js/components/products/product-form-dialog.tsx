import type { Errors } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { Package } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Products/ProductController';
import InputError from '@/components/input-error';
import { ProductVariantsEditor } from '@/components/products/product-variants-editor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Textarea } from '@/components/ui/textarea';
import {
    blankVariantDraft,
    variantCombinations,
    variantSignature,
    type OptionDraft,
    type VariantDraft,
} from '@/lib/product-variants';
import type { Product, ProductVariant } from '@/types';

type FormState = {
    name: string;
    sku: string;
    description: string;
    price: string;
    inventory_quantity: string;
    public_url: string;
    is_active: boolean;
};

const BLANK_FORM: FormState = {
    name: '',
    sku: '',
    description: '',
    price: '',
    inventory_quantity: '',
    public_url: '',
    is_active: true,
};

function formFromProduct(product: Product): FormState {
    return {
        name: product.name,
        sku: product.sku ?? '',
        description: product.description ?? '',
        price: product.price ?? '',
        inventory_quantity:
            product.inventory_quantity !== null
                ? String(product.inventory_quantity)
                : '',
        public_url: product.public_url ?? '',
        is_active: product.is_active,
    };
}

/**
 * Rebuild the options list and per-combination drafts from variants loaded
 * off the server, so editing a manual product with variants reopens with
 * its existing structure. Options keep first-appearance order; drafts are
 * keyed by the same signature the editor uses.
 */
function editorStateFromVariants(variants: ProductVariant[]): {
    options: OptionDraft[];
    drafts: Record<string, VariantDraft>;
} {
    const optionMap = new Map<string, string[]>();
    const drafts: Record<string, VariantDraft> = {};

    for (const variant of variants) {
        for (const option of variant.options) {
            const values = optionMap.get(option.name) ?? [];
            if (!values.includes(option.value)) {
                values.push(option.value);
            }
            optionMap.set(option.name, values);
        }

        const signature = variantSignature(
            variant.options.map((option) => ({
                name: option.name,
                value: option.value,
            })),
        );
        drafts[signature] = {
            sku: variant.sku ?? '',
            price: variant.price ?? '',
            inventory_quantity:
                variant.inventory_quantity !== null
                    ? String(variant.inventory_quantity)
                    : '',
            is_available: variant.is_available,
        };
    }

    return {
        options: [...optionMap].map(([name, values]) => ({ name, values })),
        drafts,
    };
}

/**
 * Create or edit a product. A store-synced product (store_id set) is
 * platform-owned — the next sync overwrites name/price/variants/etc., so
 * only its sku is editable here; every other field, and its variants, are
 * shown read-only so this reads as a full detail view. A manually-added
 * product (store_id null) is fully editable, variants included: define
 * options and their values, and each combination becomes an editable
 * variant with its own sku/price/inventory. The product-level price and
 * inventory stay as fallbacks for combinations left blank.
 */
export function ProductFormDialog({
    open,
    onOpenChange,
    product,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    product: Product | null;
}) {
    const isEdit = product !== null;
    const isSynced = isEdit && product.store_id !== null;
    const isManual = !isSynced;

    const [form, setForm] = useState<FormState>(BLANK_FORM);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [variants, setVariants] = useState<ProductVariant[]>([]);
    const [loadingVariants, setLoadingVariants] = useState(false);
    const [hasVariants, setHasVariants] = useState(false);
    const [options, setOptions] = useState<OptionDraft[]>([]);
    const [variantDrafts, setVariantDrafts] = useState<
        Record<string, VariantDraft>
    >({});

    useEffect(() => {
        if (!open) {
            return;
        }

        setForm(product ? formFromProduct(product) : BLANK_FORM);
        setErrors({});
        setVariants([]);
        setHasVariants(false);
        setOptions([]);
        setVariantDrafts({});

        if (!product) {
            return;
        }

        setLoadingVariants(true);

        fetch(ProductController.variants.url(product.id), {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((data: { variants: ProductVariant[] }) => {
                setVariants(data.variants);

                const withOptions = data.variants.filter(
                    (variant) => variant.options.length > 0,
                );

                if (product.store_id === null && withOptions.length > 0) {
                    const { options: loadedOptions, drafts } =
                        editorStateFromVariants(withOptions);
                    setHasVariants(true);
                    setOptions(loadedOptions);
                    setVariantDrafts(drafts);
                }
            })
            .finally(() => setLoadingVariants(false));
    }, [open, product]);

    const update = (changes: Partial<FormState>) =>
        setForm((current) => ({ ...current, ...changes }));

    const buildVariantPayload = () => {
        if (!hasVariants) {
            return { options: [], variants: [] };
        }

        const optionsPayload = options
            .map((option) => ({
                name: option.name.trim(),
                values: option.values
                    .map((value) => value.trim())
                    .filter(Boolean),
            }))
            .filter((option) => option.name !== '' && option.values.length > 0);

        const variantsPayload = variantCombinations(options).map(
            (combination) => {
                const draft =
                    variantDrafts[variantSignature(combination)] ??
                    blankVariantDraft();

                return {
                    options: Object.fromEntries(
                        combination.map((pair) => [pair.name, pair.value]),
                    ),
                    sku: draft.sku || null,
                    price: draft.price || null,
                    inventory_quantity: draft.inventory_quantity || null,
                    is_available: draft.is_available,
                };
            },
        );

        return { options: optionsPayload, variants: variantsPayload };
    };

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});

        const payload = isSynced
            ? { sku: form.sku || null }
            : {
                  name: form.name,
                  sku: form.sku || null,
                  description: form.description || null,
                  price: form.price || null,
                  inventory_quantity: form.inventory_quantity || null,
                  public_url: form.public_url || null,
                  is_active: form.is_active,
                  ...buildVariantPayload(),
              };

        const requestOptions = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (formErrors: Errors) => setErrors(formErrors),
            onFinish: () => setProcessing(false),
        };

        if (isEdit) {
            router.patch(
                ProductController.update.url(product.id),
                payload,
                requestOptions,
            );
        } else {
            router.post(
                ProductController.store.url(),
                payload,
                requestOptions,
            );
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {isEdit ? 'Product details' : 'Add product'}
                    </DialogTitle>
                    <DialogDescription>
                        {isSynced
                            ? `Synced from ${product.store?.name ?? 'a connected store'} — only the SKU can be edited here, everything else is overwritten on the next sync.`
                            : isEdit
                              ? 'Manually-added product — every field is editable.'
                              : 'Manually add a product that is not synced from any store.'}
                    </DialogDescription>
                </DialogHeader>

                {isEdit && (
                    <div className="flex items-center gap-3 rounded-lg border p-3">
                        <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
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
                        <div className="grid min-w-0 flex-1">
                            <span className="truncate font-medium">
                                {product.name}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {isSynced
                                    ? `#${product.external_product_id ?? product.id}`
                                    : 'Manual product'}
                            </span>
                        </div>
                        {isSynced ? (
                            <Badge variant="outline">
                                {product.store?.name ?? 'Synced'}
                            </Badge>
                        ) : (
                            <Badge variant="outline">Manual</Badge>
                        )}
                    </div>
                )}

                {isSynced && (loadingVariants || variants.length > 0) && (
                    <div className="grid gap-2">
                        <Label>Variants</Label>
                        <div className="max-h-48 overflow-y-auto rounded-lg border">
                            {loadingVariants ? (
                                <p className="p-3 text-sm text-muted-foreground">
                                    Loading variants…
                                </p>
                            ) : (
                                <table className="w-full text-sm">
                                    <tbody className="divide-y">
                                        {variants.map((variant) => (
                                            <tr key={variant.id}>
                                                <td className="p-2">
                                                    {variant.options.length >
                                                    0 ? (
                                                        variant.options
                                                            .map(
                                                                (option) =>
                                                                    option.value,
                                                            )
                                                            .join(' / ')
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Default
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="p-2 font-mono text-xs text-muted-foreground">
                                                    {variant.sku ?? '—'}
                                                </td>
                                                <td className="p-2 text-right">
                                                    {variant.price ?? '—'}
                                                </td>
                                                <td className="p-2 text-right text-muted-foreground">
                                                    {variant.inventory_quantity ??
                                                        '—'}
                                                </td>
                                                <td className="p-2 text-right">
                                                    {!variant.is_available && (
                                                        <Badge variant="outline">
                                                            Unavailable
                                                        </Badge>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={form.name}
                            onChange={(event) =>
                                update({ name: event.target.value })
                            }
                            disabled={isSynced}
                            required={!isSynced}
                            placeholder="Product name"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="sku">SKU</Label>
                        <Input
                            id="sku"
                            value={form.sku}
                            onChange={(event) =>
                                update({ sku: event.target.value })
                            }
                            placeholder="e.g. TSHIRT-RED-M"
                        />
                        <InputError message={errors.sku} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="price">
                                Price
                                {isManual && hasVariants && (
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        (default)
                                    </span>
                                )}
                            </Label>
                            <Input
                                id="price"
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.price}
                                onChange={(event) =>
                                    update({
                                        price: event.target.value,
                                    })
                                }
                                disabled={isSynced}
                            />
                            <InputError message={errors.price} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="inventory_quantity">
                                Inventory quantity
                                {isManual && hasVariants && (
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        (default)
                                    </span>
                                )}
                            </Label>
                            <Input
                                id="inventory_quantity"
                                type="number"
                                min="0"
                                value={form.inventory_quantity}
                                onChange={(event) =>
                                    update({
                                        inventory_quantity:
                                            event.target.value,
                                    })
                                }
                                disabled={isSynced}
                                placeholder={
                                    isSynced
                                        ? 'Tracked per variant'
                                        : undefined
                                }
                            />
                            <InputError
                                message={errors.inventory_quantity}
                            />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="description">Description</Label>
                        <Textarea
                            id="description"
                            value={form.description}
                            onChange={(event) =>
                                update({
                                    description: event.target.value,
                                })
                            }
                            disabled={isSynced}
                            rows={3}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="public_url">Public URL</Label>
                        <Input
                            id="public_url"
                            value={form.public_url}
                            onChange={(event) =>
                                update({
                                    public_url: event.target.value,
                                })
                            }
                            disabled={isSynced}
                            placeholder="https://…"
                        />
                        <InputError message={errors.public_url} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="is_active"
                            checked={form.is_active}
                            onCheckedChange={(checked) =>
                                update({ is_active: checked === true })
                            }
                            disabled={isSynced}
                        />
                        <Label htmlFor="is_active" className="font-normal">
                            Active
                        </Label>
                    </div>

                    {isManual && (
                        <div className="grid gap-3 border-t pt-4">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="has_variants"
                                    checked={hasVariants}
                                    onCheckedChange={(checked) =>
                                        setHasVariants(checked === true)
                                    }
                                />
                                <Label
                                    htmlFor="has_variants"
                                    className="font-normal"
                                >
                                    This product has variants (size, color, …)
                                </Label>
                            </div>

                            {hasVariants && (
                                <ProductVariantsEditor
                                    options={options}
                                    variantDrafts={variantDrafts}
                                    onOptionsChange={setOptions}
                                    onVariantDraftsChange={setVariantDrafts}
                                />
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {isEdit ? 'Save changes' : 'Create product'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
