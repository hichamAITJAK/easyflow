import { Head, Link } from '@inertiajs/react';
import { Plus, Truck } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { create as createDeliveryCourrier } from '@/routes/delivery-couriers';
import type { DeliveryAccount } from '@/types';

export default function DeliveryCouriersIndex({
    accounts,
}: {
    accounts: DeliveryAccount[];
}) {
    const [search, setSearch] = useState('');
    const [deletingAccount, setDeletingAccount] =
        useState<DeliveryAccount | null>(null);

    const filteredAccounts = useMemo(() => {
        const query = search.trim().toLowerCase();

        if (!query) {
            return accounts;
        }

        return accounts.filter((account) =>
            [
                account.courier?.name ?? '',
                account.label,
                account.collect_city?.name ?? '',
                account.status,
            ]
                .join(' ')
                .toLowerCase()
                .includes(query),
        );
    }, [accounts, search]);

    return (
        <>
            <Head title="Delivery couriers" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Delivery couriers"
                        description="Connect the delivery couriers you use to ship orders."
                    />
                    <Button asChild>
                        <Link href={createDeliveryCourrier()}>
                            <Plus />
                            Add courier
                        </Link>
                    </Button>
                </div>

                {accounts.length > 0 && (
                    <Input
                        placeholder="Filter couriers…"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="max-w-sm"
                    />
                )}

                {accounts.length === 0 ? (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyMedia
                                variant="default"
                                aria-hidden="true"
                            >
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
                            <EmptyTitle>Connect your first courier</EmptyTitle>
                            <EmptyDescription>
                                The moment it&apos;s connected, confirmed
                                orders get a parcel created and a tracking
                                number automatically — no manual shipping.
                            </EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={createDeliveryCourrier()}>
                                    <Plus />
                                    Add courier
                                </Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : filteredAccounts.length === 0 ? (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <Truck />
                            </EmptyMedia>
                            <EmptyTitle>No matches found</EmptyTitle>
                            <EmptyDescription>
                                Try a different name or city.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {filteredAccounts.map((account) => (
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
                                Add courier
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
