import { Head, Link } from '@inertiajs/react';
import { Plus, Truck } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { CourierCard } from '@/components/delivery-couriers/courier-card';
import { DeleteDeliveryAccountDialog } from '@/components/delivery-couriers/delete-delivery-account-dialog';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { create as createDeliveryCourrier } from '@/routes/delivery-couriers';
import type { DeliveryAccount } from '@/types';

export default function DeliveryCouriersIndex({
    accounts,
}: {
    accounts: DeliveryAccount[];
}) {
    const { t } = useTranslation();

    const [deletingAccount, setDeletingAccount] =
        useState<DeliveryAccount | null>(null);

    return (
        <>
            <Head title={t('Delivery couriers')} />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title={t('Delivery couriers')}
                        description={t(
                            'Connect the delivery couriers you use to ship orders.',
                        )}
                    />
                    <Button asChild>
                        <Link href={createDeliveryCourrier()}>
                            <Plus />
                            {t('Add courier')}
                        </Link>
                    </Button>
                </div>

                {accounts.length === 0 ? (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyMedia variant="default" aria-hidden="true">
                                <div className="flex items-center gap-3">
                                    <div className="flex size-12 items-center justify-center rounded-full bg-sidebar-primary text-sidebar-primary-foreground">
                                        <AppLogoIcon className="size-6 fill-current text-white dark:text-black" />
                                    </div>

                                    <div className="flex w-10 items-center justify-center gap-1">
                                        {[0, 1, 2].map((dot) => (
                                            <span
                                                key={dot}
                                                className="size-1 rounded-full bg-muted-foreground/30"
                                            />
                                        ))}
                                    </div>

                                    <div className="flex size-12 items-center justify-center rounded-full border-2 border-dashed text-muted-foreground">
                                        <Truck className="size-5" />
                                    </div>
                                </div>
                            </EmptyMedia>
                            <EmptyTitle>
                                {t('Connect your first courier')}
                            </EmptyTitle>
                            <EmptyDescription>
                                {t(
                                    "The moment it's connected, confirmed orders get a parcel created and a tracking number automatically — no manual shipping.",
                                )}
                            </EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={createDeliveryCourrier()}>
                                    <Plus />
                                    {t('Add courier')}
                                </Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {accounts.map((account) => (
                            <CourierCard
                                key={account.id}
                                account={account}
                                onDelete={setDeletingAccount}
                            />
                        ))}

                        <Link
                            href={createDeliveryCourrier()}
                            className="flex h-full min-h-[9.5rem] flex-col items-center justify-center gap-2 rounded-xl border border-dashed text-muted-foreground transition-colors hover:border-primary/50 hover:bg-muted/40 hover:text-foreground"
                        >
                            <Plus className="size-6" />
                            <span className="text-sm font-medium">
                                {t('Add courier')}
                            </span>
                        </Link>
                    </div>
                )}
            </div>

            <DeleteDeliveryAccountDialog
                open={deletingAccount !== null}
                onOpenChange={(open) => !open && setDeletingAccount(null)}
                account={deletingAccount}
            />
        </>
    );
}

DeliveryCouriersIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Delivery Couriers', href: '/delivery-couriers' },
    ],
};
