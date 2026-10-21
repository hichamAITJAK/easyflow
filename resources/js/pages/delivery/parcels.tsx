import { Head } from '@inertiajs/react';
import { DeliveryParcelsTable } from '@/components/delivery/parcels-table';
import { useTranslation } from '@/hooks/use-translation';
import type { DeliveryGuyParcel } from '@/types/delivery-guy';

/** Every parcel handed to the delivery guy, reached from his bottom bar. */
export default function DeliveryParcels({
    parcels,
}: {
    parcels: DeliveryGuyParcel[];
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Parcels')} />

            <div className="mx-auto w-full max-w-6xl px-4 pt-6 pb-4 lg:px-6 lg:pb-10">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('My parcels')}
                    </h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {t('Every parcel handed to you, newest first.')}
                    </p>
                </div>

                <DeliveryParcelsTable parcels={parcels} loading={false} />
            </div>
        </>
    );
}
