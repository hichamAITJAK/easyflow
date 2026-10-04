import { Head, Link, router } from '@inertiajs/react';
import { MapPin, Pencil, RefreshCw, Truck } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from '@/hooks/use-translation';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import {
    cities as courierCities,
    edit as editCourier,
    syncCities,
} from '@/routes/super-admin/couriers';
import type { CatalogCourier } from '@/types';

export default function SuperAdminCouriersIndex({
    couriers,
}: {
    couriers: CatalogCourier[];
}) {
    const { t } = useTranslation();

    return (
        <SuperAdminLayout>
            <Head title={t('Couriers')} />

            <div className="space-y-6">
                <Heading
                    title={t('Delivery couriers')}
                    description={t(
                        'The couriers tenants can create parcels with. Each one is backed by its own integration code, so the catalog itself is fixed — you can edit how a courier is presented and refresh its city list.',
                    )}
                />

                <div className="grid gap-4 md:grid-cols-2">
                    {couriers.map((courier) => (
                        <Card key={courier.id}>
                            <CardContent className="space-y-4">
                                <div className="flex items-start gap-4">
                                    {courier.logo ? (
                                        <img
                                            src={courier.logo}
                                            alt=""
                                            className="size-10 shrink-0 rounded-md object-contain"
                                        />
                                    ) : (
                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-md border text-muted-foreground">
                                            <Truck className="size-4" />
                                        </div>
                                    )}

                                    <div className="min-w-0 flex-1 space-y-1">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">
                                                {courier.name}
                                            </span>
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {courier.slug}
                                            </span>
                                        </div>
                                        <p className="text-sm text-balance text-muted-foreground">
                                            {courier.description ??
                                                'No description.'}
                                        </p>
                                        <p className="text-xs text-muted-foreground tabular-nums">
                                            {courier.cities_count} cities ·{' '}
                                            {courier.delivery_accounts_count}{' '}
                                            connected{' '}
                                            {courier.delivery_accounts_count ===
                                            1
                                                ? 'account'
                                                : 'accounts'}
                                        </p>
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={editCourier(courier.id)}>
                                            <Pencil />
                                            {t('Edit')}
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={courierCities(courier.id)}>
                                            <MapPin />
                                            {t('Cities')}
                                        </Link>
                                    </Button>

                                    {courier.syncable ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    syncCities(courier.id),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <RefreshCw />
                                            {t('Sync cities')}
                                        </Button>
                                    ) : (
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <span>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled
                                                    >
                                                        <RefreshCw />
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
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </SuperAdminLayout>
    );
}
