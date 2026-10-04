import {
    ArrowUpRight,
    Clock,
    MoreHorizontal,
    Plug,
    RefreshCw,
    Store as StoreIcon,
} from 'lucide-react';
import { reconnect } from '@/actions/App/Http/Controllers/Stores/StoreController';
import { StoreConnectionBadge } from '@/components/stores/store-connection-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateTime, formatRelativeTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { StoreSummary } from '@/types';

/** Strips the scheme so the domain reads as a label, not a URL. */
function bareDomain(domain: string): string {
    return domain.replace(/^https?:\/\//, '').replace(/\/$/, '');
}

/**
 * Platforms whose reconnect is a redirect out to their own authorization
 * flow. The rest are reconnected by re-entering credentials, which the
 * stores page handles with a dialog rather than a link.
 *
 * Mirrors the match arms in StoreController::reconnect.
 */
const OAUTH_PLATFORMS = ['YouCan', 'Shopify', 'LightFunnels'];

export function StoreCard({
    store,
    onDelete,
    onReconnect,
}: {
    store: StoreSummary;
    onDelete: (store: StoreSummary) => void;
    onReconnect: (store: StoreSummary) => void;
}) {
    const { t } = useTranslation();

    const failed = store.connection_status === 'failed';
    const redirectsToPlatform = OAUTH_PLATFORMS.includes(
        store.platform?.slug ?? '',
    );

    return (
        <Card
            className={cn(
                'h-full gap-0 py-0 transition-shadow hover:shadow-sm',
                failed && 'ring-destructive/25 dark:ring-destructive/25',
            )}
        >
            <div className="flex h-full flex-col p-5">
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted/30">
                        {store.logo_url ? (
                            <img
                                src={store.logo_url}
                                alt=""
                                width={40}
                                height={40}
                                loading="lazy"
                                decoding="async"
                                className="size-full object-contain p-1.5"
                            />
                        ) : (
                            <StoreIcon className="size-4 text-muted-foreground" />
                        )}
                    </div>

                    <div className="min-w-0 flex-1">
                        <h3 className="truncate leading-none font-medium">
                            {store.name}
                        </h3>

                        {store.domain ? (
                            <a
                                href={`https://${bareDomain(store.domain)}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-1.5 inline-flex max-w-full items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <span className="truncate">
                                    {bareDomain(store.domain)}
                                </span>
                                <ArrowUpRight className="size-3.5 shrink-0" />
                            </a>
                        ) : (
                            <p className="mt-1.5 text-sm text-muted-foreground">
                                {store.platform?.name ?? t('No domain set')}
                            </p>
                        )}
                    </div>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="-mt-1 -mr-2 shrink-0 text-muted-foreground"
                            >
                                <MoreHorizontal />
                                <span className="sr-only">
                                    {t('Actions for :name', {
                                        name: store.name,
                                    })}
                                </span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {store.domain && (
                                <>
                                    <DropdownMenuItem asChild>
                                        <a
                                            href={`https://${bareDomain(store.domain)}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <ArrowUpRight />
                                            {t('Visit storefront')}
                                        </a>
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                </>
                            )}
                            {redirectsToPlatform ? (
                                <DropdownMenuItem asChild>
                                    {/* A full page visit, not an Inertia one:
                                        this redirects out to the platform's
                                        own domain, which Inertia cannot
                                        follow. */}
                                    <a href={reconnect(store.id).url}>
                                        <Plug />
                                        {t('Reconnect')}
                                    </a>
                                </DropdownMenuItem>
                            ) : (
                                <DropdownMenuItem
                                    onSelect={() => onReconnect(store)}
                                >
                                    <Plug />
                                    {t('Reconnect')}
                                </DropdownMenuItem>
                            )}

                            <DropdownMenuSeparator />

                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => onDelete(store)}
                            >
                                {t('Delete')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                {store.description && (
                    <p className="mt-3 line-clamp-2 text-sm text-muted-foreground">
                        {store.description}
                    </p>
                )}

                {/* Status and its supporting facts share one line instead of a
                    separate panel: the badge carries the state, the rest is
                    fine print that shouldn't compete with it. */}
                <div className="mt-auto flex items-center justify-between gap-3 pt-5">
                    <StoreConnectionBadge status={store.connection_status} />

                    {/* A broken store's whole purpose on this page is to get
                        fixed, so the recovery is on the card rather than
                        behind the menu. It replaces the last-synced note,
                        which for a failed store is stale by definition. */}
                    {failed ? (
                        redirectsToPlatform ? (
                            <Button asChild size="sm" variant="outline">
                                <a href={reconnect(store.id).url}>
                                    <Plug />
                                    {t('Reconnect')}
                                </a>
                            </Button>
                        ) : (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => onReconnect(store)}
                            >
                                <Plug />
                                {t('Reconnect')}
                            </Button>
                        )
                    ) : (
                        <span
                            className="inline-flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
                            title={
                                store.last_synced_at
                                    ? formatDateTime(store.last_synced_at)
                                    : undefined
                            }
                        >
                            {store.last_synced_at ? (
                                <>
                                    <RefreshCw className="size-3 shrink-0" />
                                    <span className="truncate">
                                        {formatRelativeTime(
                                            store.last_synced_at,
                                        )}
                                    </span>
                                </>
                            ) : (
                                <>
                                    <Clock className="size-3 shrink-0" />
                                    <span className="truncate">
                                        {t('Never synced')}
                                    </span>
                                </>
                            )}
                        </span>
                    )}
                </div>
            </div>
        </Card>
    );
}
