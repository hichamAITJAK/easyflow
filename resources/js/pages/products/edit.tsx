import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ProductForm } from '@/components/products/product-form';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as productsIndex } from '@/routes/products';
import type { Product, ProductImage, ProductVariant } from '@/types';

export default function ProductEdit({
    product,
    images,
    variants,
}: {
    product: Product;
    images: ProductImage[];
    variants: ProductVariant[];
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={`Edit ${product.name}`} />

            <div className="mx-auto max-w-3xl space-y-6 p-4">
                <div>
                    <Link
                        href={productsIndex().url}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {t('Go back to products')}
                    </Link>
                </div>

                <ProductForm
                    product={product}
                    initialVariants={variants}
                    initialImages={images}
                />
            </div>
        </>
    );
}

ProductEdit.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Products',
            href: productsIndex().url,
        },
        {
            title: 'Edit product',
        },
    ],
};
