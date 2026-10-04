import type { Errors } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Loader2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import {
    show as showBusiness,
    update as updateBusiness,
} from '@/routes/super-admin/businesses';
import type { BusinessStatus } from '@/types';

type EditableBusiness = {
    id: number;
    name: string;
    slug: string;
    status: BusinessStatus;
};

export default function SuperAdminBusinessesEdit({
    business,
}: {
    business: EditableBusiness;
}) {
    const { t } = useTranslation();

    const [name, setName] = useState(business.name);
    const [slug, setSlug] = useState(business.slug);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});

        router.patch(
            updateBusiness(business.id).url,
            { name, slug },
            {
                preserveScroll: true,
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <SuperAdminLayout>
            <Head title={`Edit ${business.name}`} />

            <div className="mx-auto max-w-2xl space-y-6">
                <div>
                    <Link
                        href={showBusiness(business.id)}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {t('Go back to :name', { name: business.name })}
                    </Link>
                </div>

                <Heading
                    title={t('Edit business')}
                    description={t('Rename a tenant or correct its slug.')}
                />

                <form onSubmit={handleSubmit} className="space-y-8">
                    <div className="grid gap-6">
                        <Field>
                            <Label
                                htmlFor="name"
                                className="text-sm font-semibold"
                            >
                                {t('Business name')}{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                required
                                autoFocus
                                className="h-10"
                            />
                            <InputError message={errors.name} />
                        </Field>

                        <Field>
                            <Label
                                htmlFor="slug"
                                className="text-sm font-semibold"
                            >
                                {t('Slug')}{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="slug"
                                value={slug}
                                onChange={(event) =>
                                    setSlug(event.target.value)
                                }
                                required
                                className="h-10 font-mono"
                            />
                            <FieldDescription>
                                {t(
                                    'Lowercase letters, numbers, and single hyphens. Must be unique across every business.',
                                )}
                            </FieldDescription>
                            <InputError message={errors.slug} />
                        </Field>
                    </div>

                    <div className="flex items-center justify-end gap-3 border-t pt-6">
                        <Button type="button" variant="outline" asChild>
                            <Link href={showBusiness(business.id)}>
                                {t('Cancel')}
                            </Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="gap-2 px-5"
                        >
                            {processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            Save changes
                        </Button>
                    </div>
                </form>
            </div>
        </SuperAdminLayout>
    );
}
