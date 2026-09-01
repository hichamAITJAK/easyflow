import { MapPin, MoreHorizontal, Truck } from 'lucide-react';
import { ConnectionStatusBadge } from '@/components/connection-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { DeliveryAccount } from '@/types';

/**
 * Mirrors the store card's anatomy — identity row, then status and its
 * supporting fact on one closing line — so the two "connected integrations"
 * lists read as one system rather than two screens that happen to both use
 * cards.
 */
export function CourierCard({
    account,
    onDelete,
}: {
    account: DeliveryAccount;
    onDelete: (account: DeliveryAccount) => void;
}) {
    const courier = account.courier;
    const unverified = account.status !== 'active';

    return (
        <Card
            className={cn(
                'h-full gap-0 py-0 transition-shadow hover:shadow-sm',
                // An unverified account silently fails to create parcels, so
                // it stays findable while scanning the grid. Card's base uses
                // `ring`, not `border`, so the emphasis goes there.
                unverified && 'ring-amber-500/25 dark:ring-amber-500/25',
            )}
        >
            <div className="flex h-full flex-col p-5">
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted/30">
                        {courier?.logo ? (
                            <img
                                src={courier.logo}
                                alt=""
                                width={40}
                                height={40}
                                loading="lazy"
                                decoding="async"
                                className="size-full object-contain p-1.5"
                            />
                        ) : (
                            <Truck className="size-4 text-muted-foreground" />
                        )}
                    </div>

                    <div className="min-w-0 flex-1">
                        {/* The courier is the identity; the label is the
                            user's own name for this particular account, so it
                            qualifies the courier rather than standing alone. */}
                        <div className="flex min-w-0 items-center gap-2">
                            <h3 className="truncate font-medium leading-none">
                                {courier?.name ?? 'Unknown courier'}
                            </h3>
                            {account.is_default && (
                                <Badge
                                    variant="secondary"
                                    className="shrink-0 px-1.5 py-0 text-[10px] font-medium tracking-wide uppercase"
                                >
                                    Default
                                </Badge>
                            )}
                        </div>

                        <p className="mt-1.5 truncate text-sm text-muted-foreground">
                            {account.label}
                        </p>
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
                                    Actions for {account.label}
                                </span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => onDelete(account)}
                            >
                                Disconnect
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <div className="mt-auto flex items-center justify-between gap-3 pt-5">
                    <ConnectionStatusBadge tone={unverified ? 'warning' : 'live'}>
                        {unverified ? 'Unverified' : 'Connected'}
                    </ConnectionStatusBadge>

                    <span className="inline-flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                        <MapPin className="size-3 shrink-0" />
                        <span className="truncate">
                            {account.collect_city?.name ?? 'No pickup city'}
                        </span>
                    </span>
                </div>
            </div>
        </Card>
    );
}
