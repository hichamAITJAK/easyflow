import type { Errors } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    Box,
    DollarSign,
    ExternalLink,
    FileText,
    FlaskConical,
    Layers,
    Loader2,
    Lock,
    Package,
    Plus,
    Store,
    Tag,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Products/ProductController';
import Heading from '@/components/heading';
import type { GalleryImage } from '@/components/products/image-gallery';
import { ImageGallery } from '@/components/products/image-gallery';
import { ProductVariantsEditor } from '@/components/products/product-variants-editor';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupTextarea,
} from '@/components/ui/input-group';
import { Item, ItemContent, ItemMedia, ItemTitle } from '@/components/ui/item';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    blankVariantDraft,
    variantCombinations,
    variantSignature,
} from '@/lib/product-variants';
import type { OptionDraft, VariantDraft } from '@/lib/product-variants';
import { cn } from '@/lib/utils';
import { index as productsIndex } from '@/routes/products';
import type { Product, ProductImage, ProductVariant } from '@/types';

type FormState = {
    name: string;
    sku: string;
    description: string;
    price: string;
    inventory_quantity: string;
    public_url: string;
    is_active: boolean;
    is_test: boolean;
};

const BLANK_FORM: FormState = {
    name: '',
    sku: '',
    description: '',
    price: '',
    inventory_quantity: '',
    public_url: '',
    is_active: true,
    is_test: false,
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
        is_test: product.is_test,
    };
}

/**
 * Names a variant by its option pairs, for the accessible label on its SKU
 * field — every such input would otherwise announce only "SKU", leaving a
 * screen-reader user no way to tell the rows apart.
 */
function variantLabel(variant: ProductVariant): string {
    if (variant.options.length === 0) {
        return 'the default variant';
    }

    return variant.options
        .map((option) => `${option.name} ${option.value}`)
        .join(', ');
}

function galleryImagesFromProduct(images: ProductImage[]): GalleryImage[] {
    return images.map((image) => ({
        key: `image-${image.id}`,
        path: image.path,
        url: image.url,
    }));
}

/**
 * Inline "why is this grayed out" marker for a synced product's read-only
 * fields — sits right next to the field's own label so the explanation is at
 * the point of confusion, not three scrolls away in a single top-of-card
 * banner.
 */
function SyncedFieldNote() {
    return (
        <span
            title="Synced from your store — overwritten on the next sync"
            className="inline-flex items-center gap-1 text-xs font-normal text-muted-foreground"
        >
            <Lock className="size-3" />
            Synced
        </span>
    );
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
            image: variant.image ?? '',
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
 * Create or edit a product. Manual (store_id null) and synced products
 * share one layout; a store-synced product is platform-owned — the next
 * sync overwrites name/price/description/images/variants anyway — so those
 * fields render disabled, while sku, per-variant SKUs, inventory_quantity,
 * is_active, and is_test stay editable locally (matching
 * UpdateProductRequest's synced branch). Variant SKUs survive re-sync —
 * ProductSyncService deliberately leaves that column alone. A manual product is fully editable, variants included: define
 * options and their values, and each combination becomes an editable
 * variant with its own sku/price/inventory. The product-level price and
 * inventory stay as fallbacks for combinations left blank.
 */
export function ProductForm({
    product,
    initialVariants = [],
    initialImages = [],
}: {
    product: Product | null;
    initialVariants?: ProductVariant[];
    initialImages?: ProductImage[];
}) {
    const isEdit = product !== null;
    const isSynced = isEdit && product.store_id !== null;
    const isManual = !isSynced;

    const [form, setForm] = useState<FormState>(BLANK_FORM);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const variants = initialVariants;

    // Synced variants are platform-owned except for their SKU, which is
    // local courier-mapping data the sync leaves alone — so it's the one
    // field editable here, held by variant id.
    const [variantSkus, setVariantSkus] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            initialVariants.map((variant) => [variant.id, variant.sku ?? '']),
        ),
    );
    const [hasVariants, setHasVariants] = useState(false);
    const [options, setOptions] = useState<OptionDraft[]>([]);
    const [variantDrafts, setVariantDrafts] = useState<
        Record<string, VariantDraft>
    >({});
    const [confirmingRemoveVariants, setConfirmingRemoveVariants] =
        useState(false);
    const [images, setImages] = useState<GalleryImage[]>(() =>
        galleryImagesFromProduct(initialImages),
    );

    useEffect(() => {
        const initialForm = product ? formFromProduct(product) : BLANK_FORM;
        setForm(initialForm);
        setErrors({});
        setHasVariants(false);
        setOptions([]);
        setVariantDrafts({});
        setImages(galleryImagesFromProduct(initialImages));

        if (!product) {
            return;
        }

        const withOptions = variants.filter(
            (variant) => variant.options.length > 0,
        );

        if (product.store_id === null && withOptions.length > 0) {
            const { options: loadedOptions, drafts } =
                editorStateFromVariants(withOptions);
            setHasVariants(true);
            setOptions(loadedOptions);
            setVariantDrafts(drafts);
        }
    }, [product, variants, initialImages]);

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
                    image: draft.image || null,
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
            ? {
                  sku: form.sku || null,
                  inventory_quantity: form.inventory_quantity || null,
                  is_active: form.is_active,
                  is_test: form.is_test,
                  // Blank clears the mapping; the controller stores null.
                  variant_skus: Object.fromEntries(
                      Object.entries(variantSkus).map(([id, sku]) => [
                          id,
                          sku.trim() || null,
                      ]),
                  ),
              }
            : {
                  name: form.name,
                  sku: form.sku || null,
                  description: form.description || null,
                  price: form.price || null,
                  inventory_quantity: form.inventory_quantity || null,
                  public_url: form.public_url || null,
                  is_active: form.is_active,
                  is_test: form.is_test,
                  images: images
                      .filter((image) => image.path !== '')
                      .map((image) => image.path),
                  ...buildVariantPayload(),
              };

        const requestOptions = {
            preserveScroll: true,
            onSuccess: () => router.get(productsIndex()),
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
            router.post(ProductController.store.url(), payload, requestOptions);
        }
    };

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <Heading
                    title={isEdit ? form.name || product.name : 'New product'}
                    description={
                        isSynced
                            ? `Synced from ${product.store?.name ?? 'a connected store'} — SKU, inventory, and status can still be edited locally.`
                            : isEdit
                              ? 'Manage basic info, pricing, inventory, and product variants.'
                              : 'Add a new catalog item with pricing, inventory, and optional variants.'
                    }
                />

                {isEdit && (
                    <div className="flex shrink-0 items-center gap-2">
                        <Badge
                            variant={isSynced ? 'outline' : 'secondary'}
                            className="gap-1.5 px-2.5 py-1 text-xs font-medium"
                        >
                            {isSynced ? (
                                <Store className="size-3.5 text-primary" />
                            ) : (
                                <Package className="size-3.5 text-muted-foreground" />
                            )}
                            {isSynced
                                ? (product.store?.name ?? 'Synced')
                                : 'Manual'}
                        </Badge>
                        <Badge
                            variant="outline"
                            className={cn(
                                'px-2.5 py-1 text-xs font-medium',
                                form.is_active
                                    ? 'border-success/30 bg-success/10 text-success'
                                    : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {form.is_active ? 'Active' : 'Draft'}
                        </Badge>
                    </div>
                )}
            </div>

            <form onSubmit={handleSubmit} className="space-y-6">
                {/* GENERAL INFO */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FileText className="size-5 text-muted-foreground" />
                            General information
                        </CardTitle>
                        <CardDescription>
                            Name, images, and description shown to confirmation
                            agents.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="space-y-6">
                        {isSynced && (
                            <Item variant="outline">
                                <ItemMedia variant="icon">
                                    <Store />
                                </ItemMedia>
                                <ItemContent>
                                    <ItemTitle>
                                        Managed by{' '}
                                        {product.store?.name ??
                                            'Connected Store'}
                                    </ItemTitle>
                                    <p className="text-xs leading-relaxed text-muted-foreground">
                                        Core details are read-only and
                                        overwritten by the next sync. SKU,
                                        inventory, and status stay editable
                                        here.
                                    </p>
                                </ItemContent>
                            </Item>
                        )}

                        <div className="grid gap-2">
                            <div className="flex items-center gap-2">
                                <Label className="text-sm font-semibold">
                                    Images
                                </Label>
                                {isSynced && <SyncedFieldNote />}
                            </div>
                            <ImageGallery
                                images={images}
                                onChange={setImages}
                                uploadUrl={ProductController.uploadImage.url()}
                                disabled={isSynced}
                            />
                            <FieldDescription>
                                The first image is used as the cover shown in
                                your product list.
                            </FieldDescription>
                            <FieldError errors={[{ message: errors.images }]} />
                        </div>

                        <Field>
                            <div className="flex items-center gap-2">
                                <Label
                                    htmlFor="name"
                                    className="text-sm font-semibold"
                                >
                                    Product name{' '}
                                    {isManual && (
                                        <span className="text-destructive">
                                            *
                                        </span>
                                    )}
                                </Label>
                                {isSynced && <SyncedFieldNote />}
                            </div>
                            <InputGroup>
                                <InputGroupInput
                                    id="name"
                                    value={form.name}
                                    onChange={(event) =>
                                        update({
                                            name: event.target.value,
                                        })
                                    }
                                    required={isManual}
                                    disabled={isSynced}
                                    autoFocus={isManual}
                                    placeholder="e.g. Wireless Noise-Cancelling Headphones"
                                />
                            </InputGroup>
                            <FieldError errors={[{ message: errors.name }]} />
                        </Field>

                        <Field>
                            <div className="flex items-center gap-2">
                                <Label
                                    htmlFor="description"
                                    className="text-sm font-semibold"
                                >
                                    Description
                                </Label>
                                {isSynced && <SyncedFieldNote />}
                            </div>
                            <InputGroup>
                                <InputGroupTextarea
                                    id="description"
                                    value={form.description}
                                    onChange={(event) =>
                                        update({
                                            description: event.target.value,
                                        })
                                    }
                                    disabled={isSynced}
                                    placeholder="Write a clear description for this product to assist confirmation agents during customer calls…"
                                    rows={4}
                                    className="text-sm leading-relaxed"
                                />
                            </InputGroup>
                            <FieldError
                                errors={[{ message: errors.description }]}
                            />
                        </Field>

                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field>
                                <Label
                                    htmlFor="sku"
                                    className="text-sm font-semibold"
                                >
                                    SKU
                                </Label>
                                <InputGroup>
                                    <InputGroupInput
                                        id="sku"
                                        value={form.sku}
                                        onChange={(event) =>
                                            update({
                                                sku: event.target.value,
                                            })
                                        }
                                        placeholder="e.g. WNH-BLK-001"
                                        className="font-mono text-sm"
                                    />
                                </InputGroup>
                                <FieldDescription>
                                    Used to match this item with your delivery
                                    courier when dispatching parcels.
                                </FieldDescription>
                                <FieldError
                                    errors={[{ message: errors.sku }]}
                                />
                            </Field>

                            <Field>
                                <div className="flex items-center gap-2">
                                    <Label
                                        htmlFor="public_url"
                                        className="text-sm font-semibold"
                                    >
                                        Store public URL
                                    </Label>
                                    {isSynced && <SyncedFieldNote />}
                                </div>
                                <InputGroup>
                                    <InputGroupAddon aria-hidden="true">
                                        <ExternalLink />
                                    </InputGroupAddon>
                                    <InputGroupInput
                                        id="public_url"
                                        value={form.public_url}
                                        onChange={(event) =>
                                            update({
                                                public_url: event.target.value,
                                            })
                                        }
                                        disabled={isSynced}
                                        placeholder="https://yourstore.com/products/..."
                                        className="text-sm"
                                    />
                                </InputGroup>
                                <FieldDescription>
                                    Reference link accessible by confirmation
                                    agents during orders.
                                </FieldDescription>
                                <FieldError
                                    errors={[{ message: errors.public_url }]}
                                />
                            </Field>
                        </div>

                        <FieldLabel htmlFor="is_active">
                            <Field
                                orientation="horizontal"
                                className="flex items-center justify-between"
                            >
                                <div className="space-y-0.5">
                                    <p className="text-sm font-semibold">
                                        Active
                                    </p>
                                    <p className="text-xs font-normal text-muted-foreground">
                                        Shown in the product picker when
                                        creating a manual order
                                    </p>
                                </div>
                                <Switch
                                    id="is_active"
                                    checked={form.is_active}
                                    onCheckedChange={(checked) =>
                                        update({
                                            is_active: checked,
                                        })
                                    }
                                />
                            </Field>
                        </FieldLabel>

                        <FieldLabel htmlFor="is_test">
                            <Field
                                orientation="horizontal"
                                className="flex items-center justify-between"
                            >
                                <div className="space-y-0.5">
                                    <p className="text-sm font-semibold">
                                        Test product
                                    </p>
                                    <p className="text-xs font-normal text-muted-foreground">
                                        Badged as Test everywhere it appears;
                                        excluded from product counts
                                    </p>
                                </div>
                                <Switch
                                    id="is_test"
                                    checked={form.is_test}
                                    onCheckedChange={(checked) =>
                                        update({
                                            is_test: checked,
                                        })
                                    }
                                />
                            </Field>
                        </FieldLabel>

                        {form.is_test && (
                            <Alert variant="info">
                                <FlaskConical />
                                <AlertTitle>
                                    Orders with this product are test orders
                                </AlertTitle>
                                <AlertDescription>
                                    Any order containing it won&apos;t count
                                    toward team performance, and can&apos;t be
                                    shipped to a delivery courier.
                                </AlertDescription>
                            </Alert>
                        )}
                    </CardContent>
                </Card>

                {/* PRICING & INVENTORY */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Tag className="size-5 text-muted-foreground" />
                            Pricing & inventory
                        </CardTitle>
                        <CardDescription>
                            Standard price and stock, or the fallback used when
                            a variant leaves its own blank.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field>
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <Label
                                            htmlFor="price"
                                            className="text-sm font-semibold"
                                        >
                                            Base price{' '}
                                            {isManual && (
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            )}
                                        </Label>
                                        {isSynced && <SyncedFieldNote />}
                                    </div>
                                    {hasVariants && (
                                        <Badge
                                            variant="outline"
                                            className="text-[10px] font-normal"
                                        >
                                            Default for variants
                                        </Badge>
                                    )}
                                </div>
                                <InputGroup>
                                    <InputGroupAddon aria-hidden="true">
                                        <DollarSign />
                                    </InputGroupAddon>
                                    <InputGroupInput
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
                                        required={isManual}
                                        disabled={isSynced}
                                        placeholder="0.00"
                                    />
                                </InputGroup>
                                <FieldDescription>
                                    {hasVariants
                                        ? 'Applies to any variant whose own price is left blank.'
                                        : 'Standard unit price charged to COD customers.'}
                                </FieldDescription>
                                <FieldError
                                    errors={[{ message: errors.price }]}
                                />
                            </Field>

                            <Field>
                                <div className="flex items-center justify-between">
                                    <Label
                                        htmlFor="inventory_quantity"
                                        className="text-sm font-semibold"
                                    >
                                        Base inventory
                                    </Label>
                                    {hasVariants && (
                                        <Badge
                                            variant="outline"
                                            className="text-[10px] font-normal"
                                        >
                                            Default for variants
                                        </Badge>
                                    )}
                                </div>
                                <InputGroup>
                                    <InputGroupAddon aria-hidden="true">
                                        <Box />
                                    </InputGroupAddon>
                                    <InputGroupInput
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
                                        placeholder="0"
                                        className="font-mono"
                                    />
                                </InputGroup>
                                <FieldDescription>
                                    {hasVariants
                                        ? 'Applies to any variant whose own stock is left blank.'
                                        : 'Total quantity available across your warehouse.'}
                                </FieldDescription>
                                <FieldError
                                    errors={[
                                        {
                                            message: errors.inventory_quantity,
                                        },
                                    ]}
                                />
                            </Field>
                        </div>
                    </CardContent>
                </Card>

                {/* VARIANTS */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between gap-2">
                            <CardTitle className="flex items-center gap-2">
                                <Layers className="size-5 text-muted-foreground" />
                                Product variants
                            </CardTitle>
                            {isManual && hasVariants && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        if (options.length > 0) {
                                            setConfirmingRemoveVariants(true);

                                            return;
                                        }

                                        setHasVariants(false);
                                        setOptions([]);
                                        setVariantDrafts({});
                                    }}
                                    className="h-8 gap-1.5 px-2.5 text-xs text-muted-foreground hover:text-destructive"
                                >
                                    Remove variants
                                </Button>
                            )}
                        </div>
                        <CardDescription>
                            {isSynced
                                ? 'Variants and their stock are synced from your store. SKUs are yours to set and are kept on re-sync.'
                                : 'Options like Size or Color, expanded into per-combination SKU, price, and stock.'}
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        {isSynced ? (
                            variants.length === 0 ? (
                                <Empty className="border border-dashed">
                                    <EmptyHeader>
                                        <EmptyMedia variant="icon">
                                            <Layers />
                                        </EmptyMedia>
                                        <EmptyTitle>
                                            No variants exist for this product
                                        </EmptyTitle>
                                    </EmptyHeader>
                                </Empty>
                            ) : (
                                <div
                                    className="max-h-[380px] overflow-auto rounded-md border bg-[linear-gradient(to_right,var(--card),transparent_24px),linear-gradient(to_left,var(--card),transparent_24px)] bg-[length:24px_100%] bg-[position:left,right] bg-no-repeat"
                                    style={{
                                        backgroundAttachment: 'local, local',
                                    }}
                                >
                                    <Table>
                                        <TableHeader className="sticky top-0 z-10 bg-muted/60 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase backdrop-blur-md [&_th]:h-auto">
                                            <TableRow className="hover:bg-transparent">
                                                <TableHead className="p-3 pl-4 font-semibold">
                                                    Variant
                                                </TableHead>
                                                <TableHead className="p-3 font-semibold">
                                                    SKU
                                                </TableHead>
                                                <TableHead className="p-3 text-right font-semibold">
                                                    Price
                                                </TableHead>
                                                <TableHead className="p-3 text-right font-semibold">
                                                    Stock
                                                </TableHead>
                                                <TableHead className="p-3 pr-4 text-center font-semibold">
                                                    Status
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {variants.map((variant) => (
                                                <TableRow key={variant.id}>
                                                    <TableCell className="p-3 pl-4 font-medium whitespace-normal">
                                                        <div className="flex flex-wrap items-center gap-1.5">
                                                            {variant.options
                                                                .length > 0 ? (
                                                                variant.options.map(
                                                                    (
                                                                        option,
                                                                        idx,
                                                                    ) => (
                                                                        <Badge
                                                                            key={
                                                                                idx
                                                                            }
                                                                            variant="outline"
                                                                            className="bg-background"
                                                                        >
                                                                            <span className="mr-1 font-normal text-muted-foreground">
                                                                                {
                                                                                    option.name
                                                                                }

                                                                                :
                                                                            </span>
                                                                            {
                                                                                option.value
                                                                            }
                                                                        </Badge>
                                                                    ),
                                                                )
                                                            ) : (
                                                                <span className="text-muted-foreground">
                                                                    Default
                                                                </span>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="p-3">
                                                        <InputGroup className="h-8 min-w-28">
                                                            <InputGroupInput
                                                                value={
                                                                    variantSkus[
                                                                        variant
                                                                            .id
                                                                    ] ?? ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setVariantSkus(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [variant.id]:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        }),
                                                                    )
                                                                }
                                                                placeholder="—"
                                                                className="font-mono text-xs"
                                                                aria-label={`SKU for ${variantLabel(variant)}`}
                                                            />
                                                        </InputGroup>
                                                    </TableCell>
                                                    <TableCell className="p-3 text-right font-mono">
                                                        {variant.price ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="p-3 text-right font-mono">
                                                        {variant.inventory_quantity ??
                                                            '—'}
                                                    </TableCell>
                                                    <TableCell className="p-3 pr-4 text-center">
                                                        <Badge
                                                            variant="outline"
                                                            className={cn(
                                                                'text-[11px] font-medium',
                                                                variant.is_available
                                                                    ? 'border-success/30 bg-success/10 text-success'
                                                                    : 'bg-muted text-muted-foreground',
                                                            )}
                                                        >
                                                            {variant.is_available
                                                                ? 'Available'
                                                                : 'Unavailable'}
                                                        </Badge>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )
                        ) : !hasVariants ? (
                            <Empty className="border border-dashed">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Layers />
                                    </EmptyMedia>
                                    <EmptyTitle>No variants yet</EmptyTitle>
                                    <EmptyDescription>
                                        Add options like Size or Color and every
                                        combination (e.g. Small / Black) becomes
                                        its own row with its own SKU, price, and
                                        stock.
                                    </EmptyDescription>
                                </EmptyHeader>
                                <EmptyContent>
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setHasVariants(true);
                                            setOptions([
                                                { name: '', values: [] },
                                            ]);
                                        }}
                                        className="gap-2"
                                    >
                                        <Plus className="size-4" />
                                        Add options
                                    </Button>
                                </EmptyContent>
                            </Empty>
                        ) : (
                            <ProductVariantsEditor
                                options={options}
                                variantDrafts={variantDrafts}
                                onOptionsChange={setOptions}
                                onVariantDraftsChange={setVariantDrafts}
                            />
                        )}
                    </CardContent>
                </Card>

                <div className="flex items-center justify-between gap-3 border-t pt-6">
                    <div>
                        {isEdit && product && (
                            <span className="text-xs text-muted-foreground">
                                Product ID: #{product.id}
                            </span>
                        )}
                    </div>
                    <div className="flex items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(productsIndex())}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="gap-2 px-5"
                        >
                            {processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            {isEdit ? 'Save changes' : 'Create product'}
                        </Button>
                    </div>
                </div>
            </form>

            <AlertDialog
                open={confirmingRemoveVariants}
                onOpenChange={setConfirmingRemoveVariants}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Remove all variants?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {options.length}{' '}
                            {options.length === 1 ? 'option' : 'options'} and{' '}
                            {variantCombinations(options).length}{' '}
                            {variantCombinations(options).length === 1
                                ? 'combination'
                                : 'combinations'}{' '}
                            — including every per-variant SKU, price, and stock
                            you entered — will be discarded. This can&apos;t be
                            undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onClick={() => {
                                setHasVariants(false);
                                setOptions([]);
                                setVariantDrafts({});
                            }}
                        >
                            Remove variants
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
