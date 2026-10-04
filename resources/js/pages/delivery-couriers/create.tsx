import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Truck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConnectTutorialDialog } from '@/components/connect-tutorial-dialog';
import { ConnectCourierDialog } from '@/components/delivery-couriers/connect-courier-dialog';
import Heading from '@/components/heading';
import { ProviderConnectCard } from '@/components/provider-connect-card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as deliveryCouriersIndex } from '@/routes/delivery-couriers';
import type { CitiesByCourier, DeliveryCourrier } from '@/types';

export default function DeliveryCouriersCreate({
    couriers,
    citiesByCourier,
    businessSlug,
}: {
    couriers: DeliveryCourrier[];
    citiesByCourier: CitiesByCourier;
    businessSlug: string;
}) {
    const { t } = useTranslation();

    const [search, setSearch] = useState('');
    const [connectingCourier, setConnectingCourier] =
        useState<DeliveryCourrier | null>(null);
    const [tutorialCourier, setTutorialCourier] =
        useState<DeliveryCourrier | null>(null);

    const filteredCouriers = useMemo(() => {
        const query = search.trim().toLowerCase();

        if (!query) {
            return couriers;
        }

        return couriers.filter((courier) =>
            [courier.name, courier.description ?? '']
                .join(' ')
                .toLowerCase()
                .includes(query),
        );
    }, [couriers, search]);

    return (
        <>
            <Head title={t('Add delivery courier')} />

            <div className="space-y-8 p-4">
                <Link
                    href={deliveryCouriersIndex()}
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    {t('Go back')}
                </Link>

                <Heading
                    title={t('Add a delivery courier')}
                    description={t(
                        'Choose the delivery courier you want to connect.',
                    )}
                />

                <Input
                    placeholder={t('Search couriers…')}
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    className="max-w-sm"
                />

                {filteredCouriers.length === 0 ? (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Truck />
                            </EmptyMedia>
                            <EmptyTitle>{t('No matches found')}</EmptyTitle>
                            <EmptyDescription>
                                {t('Try a different search term.')}
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        {filteredCouriers.map((courier) => (
                            <ProviderConnectCard
                                key={courier.id}
                                name={courier.name}
                                description={courier.description}
                                logoUrl={courier.logo}
                                fallbackIcon={
                                    <Truck className="size-5 text-muted-foreground" />
                                }
                                onConnect={() => setConnectingCourier(courier)}
                                onViewTutorial={() =>
                                    setTutorialCourier(courier)
                                }
                            />
                        ))}
                    </div>
                )}
            </div>

            <ConnectCourierDialog
                open={connectingCourier !== null}
                onOpenChange={(open) => !open && setConnectingCourier(null)}
                courier={connectingCourier}
                citiesByCourier={citiesByCourier}
                businessSlug={businessSlug}
            />

            <ConnectTutorialDialog
                open={tutorialCourier !== null}
                onOpenChange={(open) => !open && setTutorialCourier(null)}
                name={tutorialCourier?.name ?? ''}
            />
        </>
    );
}

DeliveryCouriersCreate.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Delivery Couriers', href: '/delivery-couriers' },
        { title: 'Add courier', href: '/delivery-couriers/create' },
    ],
};
