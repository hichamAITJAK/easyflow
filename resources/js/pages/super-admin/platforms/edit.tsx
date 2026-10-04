import type { Errors } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Loader2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { LogoDropzone } from '@/components/logo-dropzone';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import {
    index as platformsIndex,
    update as updatePlatform,
} from '@/routes/super-admin/platforms';

type EditablePlatform = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    logo_url: string | null;
};

export default function SuperAdminPlatformsEdit({
    platform,
}: {
    platform: EditablePlatform;
}) {
    const { t } = useTranslation();

    const [name, setName] = useState(platform.name);
    const [description, setDescription] = useState(platform.description ?? '');
    const [logo, setLogo] = useState<File | null>(null);
    const [removeLogo, setRemoveLogo] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});

        // A file can't ride along on a PATCH body, so this posts multipart
        // with a method override — the route stays PATCH.
        router.post(
            updatePlatform(platform.id).url,
            {
                _method: 'patch',
                name,
                description: description || '',
                ...(logo ? { logo } : {}),
                ...(removeLogo ? { remove_logo: '1' } : {}),
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <SuperAdminLayout>
            <Head title={`Edit ${platform.name}`} />

            <div className="mx-auto max-w-2xl space-y-6">
                <div>
                    <Link
                        href={platformsIndex()}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {t('Go back to platforms')}
                    </Link>
                </div>

                <Heading
                    title={`Edit ${platform.name}`}
                    description={t(
                        'How this platform is presented to tenants in the store connection flow.',
                    )}
                />

                <form onSubmit={handleSubmit} className="space-y-8">
                    <div className="grid gap-6">
                        <Field>
                            <Label className="text-sm font-semibold">
                                {t('Logo')}
                            </Label>
                            <LogoDropzone
                                initialPreviewUrl={platform.logo_url}
                                onChange={(file) => {
                                    setLogo(file);

                                    if (file) {
                                        setRemoveLogo(false);
                                    }
                                }}
                                onRemove={() => setRemoveLogo(true)}
                            />
                            <FieldDescription>
                                {t(
                                    'Shown next to the platform name in the store connection flow.',
                                )}
                            </FieldDescription>
                            <InputError message={errors.logo} />
                        </Field>

                        <Field>
                            <Label
                                htmlFor="name"
                                className="text-sm font-semibold"
                            >
                                {t('Name')}{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                required
                                className="h-10"
                            />
                            <InputError message={errors.name} />
                        </Field>

                        <Field>
                            <Label className="text-sm font-semibold">
                                {t('Slug')}
                            </Label>
                            <Input
                                value={platform.slug}
                                readOnly
                                disabled
                                className="h-10 font-mono"
                            />
                            <FieldDescription>
                                {t(
                                    'Fixed. The integration code looks this platform up by its slug, so changing it would break every store already connected through it.',
                                )}
                            </FieldDescription>
                        </Field>

                        <Field>
                            <Label
                                htmlFor="description"
                                className="text-sm font-semibold"
                            >
                                {t('Description')}
                            </Label>
                            <Textarea
                                id="description"
                                value={description}
                                onChange={(event) =>
                                    setDescription(event.target.value)
                                }
                                rows={3}
                            />
                            <FieldDescription>
                                {t(
                                    'Shown under the platform name when a tenant picks where to connect from.',
                                )}
                            </FieldDescription>
                            <InputError message={errors.description} />
                        </Field>
                    </div>

                    <div className="flex items-center justify-end gap-3 border-t pt-6">
                        <Button type="button" variant="outline" asChild>
                            <Link href={platformsIndex()}>{t('Cancel')}</Link>
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
