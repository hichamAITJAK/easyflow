import { Head, Link, router } from '@inertiajs/react';
import { Plus, Store as StoreIcon } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import Heading from '@/components/heading';
import { DeleteStoreDialog } from '@/components/stores/delete-store-dialog';
import { StoreCard } from '@/components/stores/store-card';
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
import { create as createStore } from '@/routes/stores';
import type { StoreSummary } from '@/types';

export default function StoresIndex({ stores }: { stores: StoreSummary[] }) {
    const { t } = useTranslation();

    const [deletingStore, setDeletingStore] = useState<StoreSummary | null>(
        null,
    );

    /**
     * Platforms with no OAuth redirect are reconnected by re-entering
     * credentials, and the form for that already lives on the connect
     * screen — sending the merchant there reuses it rather than growing a
     * second copy on this page. `reconnect` tells that screen which store
     * is being repaired, so it can open the right platform's dialog.
     */
    const reconnectWithCredentials = (store: StoreSummary) => {
        router.get(createStore().url, { reconnect: store.id });
    };

    return (
        <>
            <Head title={t('Stores')} />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title={t('Stores')}
                        description={t(
                            'Connect your e-commerce stores and delivery couriers.',
                        )}
                    />
                    <Button asChild>
                        <Link href={createStore()}>
                            <Plus />
                            {t('Add store')}
                        </Link>
                    </Button>
                </div>

                {stores.length === 0 ? (
                    <Empty className="border">
                        <EmptyHeader>
                            <EmptyMedia variant="default" aria-hidden="true">
                                <div className="flex items-center gap-3">
                                    <div className="flex size-12 items-center justify-center rounded-full border-2 border-dashed text-muted-foreground">
                                        <StoreIcon className="size-5" />
                                    </div>

                                    <div className="flex w-10 items-center justify-center gap-1">
                                        {[0, 1, 2].map((dot) => (
                                            <span
                                                key={dot}
                                                className="size-1 rounded-full bg-muted-foreground/30"
                                            />
                                        ))}
                                    </div>

                                    <div className="flex size-12 items-center justify-center rounded-full bg-sidebar-primary text-sidebar-primary-foreground">
                                        <AppLogoIcon className="size-6 fill-current text-white dark:text-black" />
                                    </div>
                                </div>
                            </EmptyMedia>
                            <EmptyTitle>
                                {t('Connect your first store')}
                            </EmptyTitle>
                            <EmptyDescription>
                                {t(
                                    'Shopify and YouCan orders start flowing in the moment you connect — no manual entry, no spreadsheets.',
                                )}
                            </EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={createStore()}>
                                    <Plus />
                                    {t('Add store')}
                                </Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {stores.map((store) => (
                            <StoreCard
                                key={store.id}
                                store={store}
                                onDelete={setDeletingStore}
                                onReconnect={reconnectWithCredentials}
                            />
                        ))}

                        <Link href={createStore()} className="group h-full">
                            <Empty className="h-full min-h-[9.5rem] border border-dashed transition-colors group-hover:border-primary/50 group-hover:bg-muted/40">
                                <EmptyHeader>
                                    <EmptyMedia
                                        variant="icon"
                                        className="transition-colors group-hover:bg-primary/10 group-hover:text-primary"
                                    >
                                        <Plus />
                                    </EmptyMedia>
                                    <EmptyTitle className="text-sm transition-colors group-hover:text-foreground">
                                        {t('Add store')}
                                    </EmptyTitle>
                                </EmptyHeader>
                            </Empty>
                        </Link>
                    </div>
                )}
            </div>

            <DeleteStoreDialog
                open={deletingStore !== null}
                onOpenChange={(open) => !open && setDeletingStore(null)}
                store={deletingStore}
            />
        </>
    );
}

StoresIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Stores',
            href: '/stores',
        },
    ],
};
