import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ProductForm } from '@/components/products/product-form';
import { dashboard } from '@/routes';
import { index as productsIndex } from '@/routes/products';

export default function ProductCreate() {
    return (
        <>
            <Head title="Add product" />

            <div className="mx-auto max-w-3xl space-y-6 p-4">
                <div>
                    <Link
                        href={productsIndex().url}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Go back to products
                    </Link>
                </div>

                <ProductForm product={null} />
            </div>
        </>
    );
}

ProductCreate.layout = {
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
            title: 'Add product',
        },
    ],
};
