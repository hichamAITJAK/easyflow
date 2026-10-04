import { Head, Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { index as storesIndex } from '@/routes/stores';
import type { Store } from '@/types';

const FLOW_DOT_DELAYS_MS = [0, 220, 440];

export default function StoresConnected({ store }: { store: Store }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Store connected')} />

            <div className="mx-auto flex max-w-4xl flex-col items-center justify-center space-y-10 p-4 py-16 text-center">
                <div className="flex items-center gap-4" aria-hidden="true">
                    <Avatar className="size-14 border">
                        <AvatarImage
                            src={store.platform?.logo_url ?? undefined}
                            alt=""
                        />
                        <AvatarFallback className="text-base">
                            {store.platform?.name?.slice(0, 2) ?? '—'}
                        </AvatarFallback>
                    </Avatar>

                    <div className="flex w-16 items-center justify-center gap-1.5">
                        {FLOW_DOT_DELAYS_MS.map((delay) => (
                            <span
                                key={delay}
                                className="size-1.5 rounded-full bg-primary motion-safe:animate-[conbird-flow_1.6s_ease-in-out_infinite]"
                                style={{ animationDelay: `${delay}ms` }}
                            />
                        ))}
                    </div>

                    <div className="relative">
                        <div className="flex size-14 items-center justify-center rounded-full bg-sidebar-primary text-sidebar-primary-foreground">
                            <AppLogoIcon className="size-7 fill-current text-white dark:text-black" />
                        </div>
                        <div className="absolute -right-1 -bottom-1 flex size-5 items-center justify-center rounded-full border-2 border-background bg-success">
                            <Check
                                className="size-3 text-white"
                                strokeWidth={3}
                            />
                        </div>
                    </div>
                </div>

                <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t(':name is connected', { name: store.name })}
                    </h1>
                    <p className="mx-auto max-w-md text-muted-foreground">
                        {t(
                            'Recent orders and products from :name are importing now — new ones will sync into EasyFlow automatically from here.',
                            { name: store.platform?.name ?? t('your store') },
                        )}
                    </p>
                </div>

                <Button size="lg" className="min-w-48" asChild>
                    <Link href={storesIndex()}>{t('Done')}</Link>
                </Button>
            </div>
        </>
    );
}

StoresConnected.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Stores',
            href: '/stores',
        },
        {
            title: 'Store connected',
            href: '#',
        },
    ],
};
