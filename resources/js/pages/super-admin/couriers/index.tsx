import type { Errors } from '@inertiajs/core';
import { Head, router } from '@inertiajs/react';
import { Loader2, MapPin, Pencil, RefreshCw, Truck } from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useTranslation } from '@/hooks/use-translation';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import {
    cities as courierCities,
    syncCities,
    update as updateCourier,
} from '@/routes/super-admin/couriers';
import type { CatalogCourier, CourierCity } from '@/types';

const ACTION_BUTTON =
    'h-9 gap-2 rounded-md bg-card px-3 text-sm font-medium shadow-xs hover:bg-accent hover:text-accent-foreground';

export default function SuperAdminCouriersIndex({
    couriers,
}: {
    couriers: CatalogCourier[];
}) {
    const { t } = useTranslation();
    const [editingId, setEditingId] = useState<number | null>(null);
    const [citiesId, setCitiesId] = useState<number | null>(null);
    const [syncingId, setSyncingId] = useState<number | null>(null);

    const editing = couriers.find((c) => c.id === editingId) ?? null;
    const citiesOf = couriers.find((c) => c.id === citiesId) ?? null;

    const sync = (courier: CatalogCourier) =>
        router.post(
            syncCities(courier.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setSyncingId(courier.id),
                onFinish: () => setSyncingId(null),
            },
        );

    return (
        <SuperAdminLayout fullWidth>
            <Head title={t('Couriers')} />

            <div className="pb-5">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('Delivery couriers')}
                </h1>
                <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                    {t(
                        'The couriers tenants can create parcels with. Each one is backed by its own integration code, so the catalog itself is fixed — you can edit how a courier is presented and refresh its city list.',
                    )}
                </p>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {couriers.map((courier) => (
                    <div
                        key={courier.id}
                        className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-xs transition-colors hover:bg-muted/30"
                    >
                        <div className="flex gap-4">
                            <CourierLogo courier={courier} />
                            <div className="min-w-0 flex-1">
                                <p className="font-semibold">
                                    {courier.name}{' '}
                                    <span className="ml-1 font-mono text-xs font-normal text-muted-foreground">
                                        {courier.slug}
                                    </span>
                                </p>
                                <p className="mt-1.5 text-sm text-muted-foreground">
                                    {courier.description ??
                                        t('No description.')}
                                </p>
                                <p className="mt-3 text-sm text-muted-foreground">
                                    <span className="font-mono tabular-nums">
                                        {courier.cities_count}
                                    </span>{' '}
                                    {t('cities')} ·{' '}
                                    <span className="font-mono tabular-nums">
                                        {courier.delivery_accounts_count}
                                    </span>{' '}
                                    {courier.delivery_accounts_count === 1
                                        ? t('connected account')
                                        : t('connected accounts')}
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                className={ACTION_BUTTON}
                                onClick={() => setEditingId(courier.id)}
                            >
                                <Pencil className="size-4" />
                                {t('Edit')}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                className={ACTION_BUTTON}
                                onClick={() => setCitiesId(courier.id)}
                            >
                                <MapPin className="size-4" />
                                {t('Cities')}
                            </Button>

                            {courier.syncable ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className={ACTION_BUTTON}
                                    disabled={syncingId !== null}
                                    onClick={() => sync(courier)}
                                >
                                    <RefreshCw
                                        className={
                                            syncingId === courier.id
                                                ? 'size-4 animate-spin'
                                                : 'size-4'
                                        }
                                    />
                                    {t('Sync cities')}
                                </Button>
                            ) : (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <span>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className={ACTION_BUTTON}
                                                disabled
                                            >
                                                <RefreshCw className="size-4" />
                                                {t('Sync cities')}
                                            </Button>
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {t(
                                            'No cities API is wired up for this courier yet.',
                                        )}
                                    </TooltipContent>
                                </Tooltip>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            <EditCourierSheet
                key={`edit-${editing?.id ?? 'none'}`}
                courier={editing}
                onClose={() => setEditingId(null)}
            />
            <CitiesSheet
                key={`cities-${citiesOf?.id ?? 'none'}`}
                courier={citiesOf}
                onClose={() => setCitiesId(null)}
            />
        </SuperAdminLayout>
    );
}

/**
 * The uploaded logo when there is one, else the icon shipped for this
 * slug, else a truck glyph — a card never shows an empty tile.
 */
function CourierLogo({ courier }: { courier: CatalogCourier }) {
    const sources = [courier.logo, courier.default_logo].filter(
        (src): src is string => !!src,
    );
    const [index, setIndex] = useState(0);
    const src = sources[index];

    return (
        <span className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-card">
            {src ? (
                <img
                    src={src}
                    alt={courier.name}
                    className="size-8 object-contain"
                    onError={() => setIndex((i) => i + 1)}
                />
            ) : (
                <Truck className="size-5 text-muted-foreground" />
            )}
        </span>
    );
}

function EditCourierSheet({
    courier,
    onClose,
}: {
    courier: CatalogCourier | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [name, setName] = useState(courier?.name ?? '');
    const [description, setDescription] = useState(courier?.description ?? '');
    const [logo, setLogo] = useState<File | null>(null);
    const [removeLogo, setRemoveLogo] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    const save = () => {
        if (!courier) {
            return;
        }

        setProcessing(true);
        setErrors({});

        // A file can't ride along on a PATCH body, so this posts multipart
        // with a method override — the route stays PATCH.
        router.post(
            updateCourier(courier.id).url,
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
            open={courier !== null}
            onOpenChange={(open: boolean) => !open && onClose()}
        >
            <SheetContent
                side="right"
                className="w-full gap-0 bg-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border p-5">
                    <SheetTitle className="text-base font-semibold tracking-tight">
                        {t('Edit')} · {courier?.name}
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
                            initialPreviewUrl={courier?.logo ?? null}
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
                            htmlFor="courier-name"
                            className="text-sm font-medium"
                        >
                            {t('Display name')}
                        </Label>
                        <Input
                            id="courier-name"
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
                            value={courier?.slug ?? ''}
                            readOnly
                            disabled
                            className="h-9 rounded-md border-input bg-muted/50 font-mono text-muted-foreground shadow-xs"
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label
                            htmlFor="courier-description"
                            className="text-sm font-medium"
                        >
                            {t('Description')}
                        </Label>
                        <Textarea
                            id="courier-description"
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

type CityPage = {
    data: CourierCity[];
    current_page: number;
    last_page: number;
    total: number;
};

/**
 * The courier's city list, searchable, read as JSON from the cities
 * route a page of 100 at a time.
 */
function CitiesSheet({
    courier,
    onClose,
}: {
    courier: CatalogCourier | null;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const query = useDebouncedValue(search, 300);
    const [cities, setCities] = useState<CourierCity[]>([]);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [total, setTotal] = useState<number | null>(null);
    // Loading is derived: the request for the current search+page is in
    // flight until its response records that key as loaded.
    const requestKey = `${query}|${page}`;
    const [loadedKey, setLoadedKey] = useState<string | null>(null);
    const loading = courier !== null && loadedKey !== requestKey;

    useEffect(() => {
        if (!courier) {
            return;
        }

        const controller = new AbortController();
        const url = courierCities(courier.id, {
            query: {
                search: query || undefined,
                per_page: 100,
                page,
            },
        }).url;
        fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((res) => res.json() as Promise<CityPage>)
            .then((json) => {
                setCities((prev) =>
                    page === 1 ? json.data : [...prev, ...json.data],
                );
                setLastPage(json.last_page);
                setTotal(json.total);
            })
            .catch(() => {})
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoadedKey(`${query}|${page}`);
                }
            });

        return () => controller.abort();
    }, [courier, query, page]);

    return (
        <Sheet
            open={courier !== null}
            onOpenChange={(open: boolean) => !open && onClose()}
        >
            <SheetContent
                side="right"
                className="w-full gap-0 bg-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border p-5">
                    <SheetTitle className="text-base font-semibold tracking-tight">
                        {courier?.name} · {t('cities')}
                    </SheetTitle>
                    <SheetDescription className="text-xs">
                        {t(':count cities in the catalog', {
                            count: courier?.cities_count ?? 0,
                        })}
                    </SheetDescription>
                </SheetHeader>

                <div className="border-b border-border p-5">
                    <Input
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                        placeholder={t('Search a city…')}
                        aria-label={t('Search a city…')}
                        className="h-9 rounded-md border-input bg-card shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                    />
                    {query && total !== null && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            {t(':count matches', { count: total })}
                        </p>
                    )}
                </div>

                <div className="flex-1 space-y-1.5 overflow-y-auto p-5">
                    {cities.map((city) => (
                        <div
                            key={city.id}
                            className="flex items-center gap-2.5 rounded-md border border-border bg-card px-3 py-2.5 text-sm"
                        >
                            <MapPin className="size-4 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1 truncate font-medium">
                                {city.name}
                            </span>
                            {city.arabic_name && (
                                <span
                                    dir="rtl"
                                    className="truncate text-xs text-muted-foreground"
                                >
                                    {city.arabic_name}
                                </span>
                            )}
                            {city.external_courrier_id && (
                                <span className="font-mono text-xs text-muted-foreground">
                                    #{city.external_courrier_id}
                                </span>
                            )}
                        </div>
                    ))}

                    {loading &&
                        Array.from({ length: page === 1 ? 8 : 3 }).map(
                            (_, i) => <Skeleton key={i} className="h-10" />,
                        )}

                    {!loading && cities.length === 0 && (
                        <p className="py-10 text-center text-sm text-muted-foreground">
                            {query
                                ? t('No city matches this search.')
                                : t('No cities yet. Run a sync to pull them.')}
                        </p>
                    )}

                    {!loading && page < lastPage && (
                        <Button
                            type="button"
                            variant="outline"
                            className="mt-2 h-9 w-full rounded-md shadow-xs"
                            onClick={() => setPage((p) => p + 1)}
                        >
                            {t('Load more')}
                        </Button>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
