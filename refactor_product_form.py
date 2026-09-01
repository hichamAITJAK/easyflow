import re

with open('resources/js/components/products/product-form.tsx', 'r') as f:
    content = f.read()

# 1. Update imports
content = content.replace("import { useEffect, useState } from 'react';", "import { useEffect, useState } from 'react';\nimport { productsIndex } from '@/routes/products';")
content = re.sub(r'import \{\s*Dialog,\s*DialogContent,\s*DialogDescription,\s*DialogFooter,\s*DialogHeader,\s*DialogTitle,\s*\} from \'@/components/ui/dialog\';\n', '', content)
content = re.sub(r'import \{\s*Tabs,\s*TabsContent,\s*TabsList,\s*TabsTrigger,\s*\} from \'@/components/ui/tabs\';\n', '', content)

# 2. Update function signature and initial state
content = re.sub(
    r'export function ProductFormDialog\(\{\s*open,\s*onOpenChange,\s*product,\s*\}\:\s*\{\s*open\: boolean;\s*onOpenChange\: \(open\: boolean\) \=\> void;\s*product\: Product \| null;\s*\}\) \{',
    'export function ProductForm({ product, initialVariants = [] }: { product: Product | null; initialVariants?: ProductVariant[] }) {',
    content
)

# 3. Update state initialization
content = content.replace('const [activeTab, setActiveTab] = useState<string>(\'general\');\n', '')
content = content.replace('const [variants, setVariants] = useState<ProductVariant[]>([]);\n    const [loadingVariants, setLoadingVariants] = useState(false);\n', 'const variants = initialVariants;\n')

# 4. Update useEffect logic (remove fetch since variants are passed in)
use_effect_old = """    useEffect(() => {
        if (!open) {
            return;
        }

        const initialForm = product ? formFromProduct(product) : BLANK_FORM;
        setForm(initialForm);
        setErrors({});
        setVariants([]);
        setHasVariants(false);
        setOptions([]);
        setVariantDrafts({});
        setActiveTab(product && product.store_id !== null ? 'overview' : 'general');

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
    }, [open, product]);"""

use_effect_new = """    useEffect(() => {
        const initialForm = product ? formFromProduct(product) : BLANK_FORM;
        setForm(initialForm);
        setErrors({});
        setHasVariants(false);
        setOptions([]);
        setVariantDrafts({});

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
    }, [product, variants]);"""

content = content.replace(use_effect_old, use_effect_new)

# 5. Update submission success handling
content = content.replace('onSuccess: () => onOpenChange(false)', 'onSuccess: () => router.get(productsIndex())')
content = content.replace('onClick={() => onOpenChange(false)}', 'onClick={() => router.get(productsIndex())}')

# 6. Replace Dialog wrappers and Tabs with a stacked layout
# We will use simple string replacements to strip the Dialog and Tabs markup.
content = content.replace('<Dialog open={open} onOpenChange={onOpenChange}>', '')
content = content.replace('</Dialog>', '')
content = content.replace('<DialogContent className="sm:max-w-3xl md:max-w-4xl max-h-[90vh] flex flex-col p-0 overflow-hidden border shadow-xl">', '<div className="flex-1 flex flex-col min-h-0 bg-background">')
content = content.replace('</DialogContent>', '</div>')

content = content.replace('<DialogTitle', '<h1')
content = content.replace('</DialogTitle>', '</h1>')
content = content.replace('<DialogDescription', '<p')
content = content.replace('</DialogDescription>', '</p>')
content = content.replace('<DialogFooter className="px-6 py-4 border-t bg-muted/30 shrink-0 sm:justify-between">', '<div className="sticky bottom-0 z-10 flex items-center justify-end gap-3 border-t bg-card/95 backdrop-blur px-6 py-4 md:px-8 shadow-sm">')
content = content.replace('</DialogFooter>', '</div>')

# Remove the entire Tabs Navigation Header block
tabs_nav_pattern = r'\{\/\* Tabs Navigation Header \*\/\}.*?<\/Tabs>'
content = re.sub(tabs_nav_pattern, '', content, flags=re.DOTALL)

# Adjust the main form container
content = content.replace('<form\n                    onSubmit={handleSubmit}\n                    className="flex flex-1 flex-col min-h-0 overflow-hidden"\n                >', '<form onSubmit={handleSubmit} className="flex-1 flex flex-col min-h-0">')
content = content.replace('<div className="flex-1 overflow-y-auto p-6">', '<div className="flex-1 space-y-12 p-6 md:p-8">')
content = content.replace('<Tabs value={activeTab} className="w-full">', '')
content = content.replace('</Tabs>', '')

# Replace TabsContent tags with standard divs having section headers
# MANUAL TAB: GENERAL INFO
content = content.replace('<TabsContent\n                                    value="general"\n                                    className="m-0 space-y-6 focus:outline-none animate-in fade-in-50 duration-200"\n                                >', '<div>\n<div className="mb-6 flex items-center gap-2 border-b pb-2">\n<FileText className="size-5 text-muted-foreground" />\n<h2 className="text-lg font-semibold tracking-tight">General Information</h2>\n</div>\n<div className="space-y-6">')
content = content.replace('</TabsContent>', '</div></div>')

# MANUAL TAB: PRICING
content = content.replace('<TabsContent\n                                    value="pricing"\n                                    className="m-0 space-y-6 focus:outline-none animate-in fade-in-50 duration-200"\n                                >', '<div>\n<div className="mb-6 flex items-center gap-2 border-b pb-2">\n<Tag className="size-5 text-muted-foreground" />\n<h2 className="text-lg font-semibold tracking-tight">Pricing & Inventory</h2>\n</div>\n<div className="space-y-6">')

# MANUAL TAB: VARIANTS
content = content.replace('<TabsContent\n                                    value="variants"\n                                    className="m-0 space-y-6 focus:outline-none animate-in fade-in-50 duration-200"\n                                >', '<div>\n<div className="mb-6 flex items-center gap-2 border-b pb-2">\n<Layers className="size-5 text-muted-foreground" />\n<h2 className="text-lg font-semibold tracking-tight">Product Variants</h2>\n</div>\n<div className="space-y-6">')

# SYNCED TAB: OVERVIEW
content = content.replace('<TabsContent\n                                    value="overview"\n                                    className="m-0 space-y-6 focus:outline-none animate-in fade-in-50 duration-200"\n                                >', '<div>\n<div className="mb-6 flex items-center gap-2 border-b pb-2">\n<Package className="size-5 text-muted-foreground" />\n<h2 className="text-lg font-semibold tracking-tight">Overview & SKU Mapping</h2>\n</div>\n<div className="space-y-6">')

# SYNCED TAB: VARIANTS
content = content.replace('<TabsContent\n                                    value="variants"\n                                    className="m-0 space-y-6 focus:outline-none animate-in fade-in-50 duration-200"\n                                >', '<div>\n<div className="mb-6 flex items-center gap-2 border-b pb-2">\n<Layers className="size-5 text-muted-foreground" />\n<h2 className="text-lg font-semibold tracking-tight">Synced Variants</h2>\n</div>\n<div className="space-y-6">')

# Finally, clean up the top header styling
content = content.replace('<div className="px-6 pt-6 pb-4 border-b bg-muted/30 shrink-0 space-y-4">', '<div className="px-6 py-6 border-b shrink-0 space-y-4">')

with open('resources/js/components/products/product-form.tsx', 'w') as f:
    f.write(content)

print("Refactoring complete.")
