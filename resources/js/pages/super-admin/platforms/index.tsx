import type { Errors } from '@inertiajs/core';
import { Head, router } from '@inertiajs/react';
import { Loader2, Pencil, Store } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { LogoDropzone } from '@/components/logo-dropzone';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import { update as updatePlatform } from '@/routes/super-admin/platforms';
import type { CatalogPlatform } from '@/types';

export default function SuperAdminPlatformsIndex({
    platforms,
}: {
    platforms: CatalogPlatform[];
}) {
    const { t } = useTranslation();
    const [editingId, setEditingId] = useState<number | null>(null);
    const editing = platforms.find((p) => p.id === editingId) ?? null;

    return (
        <SuperAdminLayout fullWidth>
            <Head title={t('Platforms')} />

            <div className="pb-5">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('E-commerce platforms')}
                </h1>
                <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                    {t(
                        'The platforms tenants can connect a store from. Each one is backed by its own integration code, so the catalog itself is fixed — you can edit how a platform is presented.',
                    )}
                </p>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {platforms.map((platform) => (
                    <div
                        key={platform.id}
                        className="flex gap-4 rounded-xl border border-border bg-card p-5 shadow-xs transition-colors hover:bg-muted/30"
                    >
                        <PlatformLogo platform={platform} />

                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <p className="font-semibold">
                                    {platform.name}{' '}
                                    <span className="ml-1 font-mono text-xs font-normal text-muted-foreground">
                                        {platform.slug}
                                    </span>
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-9 shrink-0 gap-2 rounded-md bg-card px-3 text-sm font-medium shadow-xs hover:bg-accent hover:text-accent-foreground"
                                    onClick={() => setEditingId(platform.id)}
                                >
                                    <Pencil className="size-4" />
                                    {t('Edit')}
                                </Button>
                            </div>
                            <p className="mt-1.5 text-sm text-muted-foreground">
                                {platform.description ?? t('No description.')}
                            </p>
                            <p className="mt-3 text-sm text-muted-foreground">
                                <span className="font-mono tabular-nums">
                                    {platform.stores_count}
                                </span>{' '}
                                {platform.stores_count === 1
                                    ? t('connected store')
                                    : t('connected stores')}
                            </p>
                        </div>
                    </div>
                ))}
            </div>

            <EditPlatformSheet
                key={editing?.id ?? 'none'}
                platform={editing}
                onClose={() => setEditingId(null)}
            />
        </SuperAdminLayout>
    );
}

/**
 * The uploaded logo when there is one, else the icon shipped for this
 * slug, else a neutral store glyph — a card never shows an empty tile.
 */
function PlatformLogo({ platform }: { platform: CatalogPlatform }) {
    const sources = [platform.logo_url, platform.default_logo_url].filter(
        (src): src is string => !!src,
    );
    const [index, setIndex] = useState(0);
    const src = sources[index];

    return (
        <span className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-card">
            {src ? (
                <img
                    src={src}
                    alt={platform.name}
                    className="size-8 object-contain"
                    onError={() => setIndex((i) => i + 1)}
                />
            ) : (
                <Store className="size-5 text-muted-foreground" />
            )}
        </span>
    );
}

function EditPlatformSheet({
    platform,
    onClose,
}: {
    platform: CatalogPlatform | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [name, setName] = useState(platform?.name ?? '');
    const [description, setDescription] = useState(platform?.description ?? '');
    const [logo, setLogo] = useState<File | null>(null);
    const [removeLogo, setRemoveLogo] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    const save = () => {
        if (!platform) {
            return;
        }

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
                onSuccess: onClose,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Sheet
            open={platform !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent
                side="right"
                className="w-full gap-0 bg-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border p-5">
                    <SheetTitle className="text-base font-semibold tracking-tight">
                        {t('Edit')} · {platform?.name}
                    </SheetTitle>
                    <SheetDescription className="text-xs">
                        {t(
                            'Presentation only — the integration code stays fixed.',
                        )}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-4 overflow-y-auto p-5">
                    <div className="grid gap-1.5">
                        <Label className="text-sm font-medium">
                            {t('Logo')}
                        </Label>
                        <LogoDropzone
                            initialPreviewUrl={platform?.logo_url ?? null}
                            onChange={(file) => {
                                setLogo(file);

                                if (file) {
                                    setRemoveLogo(false);
                                }
                            }}
                            onRemove={() => setRemoveLogo(true)}
                        />
                        <InputError message={errors.logo} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label
                            htmlFor="platform-name"
                            className="text-sm font-medium"
                        >
                            {t('Display name')}
                        </Label>
                        <Input
                            id="platform-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            className="h-9 rounded-md border-input bg-card shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label className="text-sm font-medium">
                            {t('Slug')}
                        </Label>
                        <Input
                            value={platform?.slug ?? ''}
                            readOnly
                            disabled
                            className="h-9 rounded-md border-input bg-muted/50 font-mono text-muted-foreground shadow-xs"
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label
                            htmlFor="platform-description"
                            className="text-sm font-medium"
                        >
                            {t('Description')}
                        </Label>
                        <Textarea
                            id="platform-description"
                            rows={4}
                            value={description}
                            onChange={(event) =>
                                setDescription(event.target.value)
                            }
                            className="rounded-md border-input bg-card shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                        />
                        <InputError message={errors.description} />
                    </div>
                </div>

                <SheetFooter className="flex-row justify-end gap-2 border-t border-border p-5">
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-9 px-4 text-muted-foreground"
                        onClick={onClose}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="button"
                        disabled={processing || !name.trim()}
                        className="h-9 gap-2 px-4 font-semibold hover:opacity-90"
                        onClick={save}
                    >
                        {processing && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        {t('Save changes')}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
