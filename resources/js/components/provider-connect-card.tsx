import { ArrowRight, BookOpen } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * One provider in a "pick what to connect" grid, shared by the store and
 * courier create pages.
 *
 * The whole card is the connect affordance rather than a card that happens
 * to contain a Connect button — picking a provider is the only reason this
 * grid exists, so the click target is the full surface and the tutorial is
 * demoted to a quiet secondary action that doesn't compete with it.
 */
export function ProviderConnectCard({
    name,
    description,
    logoUrl,
    fallbackIcon,
    connectLabel = 'Connect',
    disabled = false,
    onConnect,
    onViewTutorial,
    className,
}: {
    name: string;
    description?: string | null;
    logoUrl?: string | null;
    fallbackIcon: React.ReactNode;
    connectLabel?: string;
    disabled?: boolean;
    onConnect: () => void;
    onViewTutorial: () => void;
    className?: string;
}) {
    const [logoFailed, setLogoFailed] = useState(false);
    const showLogo = logoUrl && !logoFailed;

    return (
        <Card
            className={cn(
                'group relative h-full gap-0 overflow-hidden py-0 transition-shadow',
                disabled
                    ? 'bg-muted/30'
                    : 'focus-within:ring-2 focus-within:ring-primary/40 hover:shadow-sm hover:ring-primary/30',
                className,
            )}
        >
            <div className="flex flex-1 flex-col p-5">
                <div className="flex items-center gap-3">
                    <div className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-background">
                        {showLogo ? (
                            <img
                                src={logoUrl}
                                alt=""
                                loading="lazy"
                                decoding="async"
                                className="size-full object-contain p-1.5"
                                onError={() => setLogoFailed(true)}
                            />
                        ) : (
                            fallbackIcon
                        )}
                    </div>

                    <h3 className="min-w-0 flex-1 truncate font-medium">
                        {name}
                    </h3>

                    {/* An unavailable provider says so up front, next to the
                        name. Leaving it looking connectable until the click
                        fails is the worse outcome, and the state belongs with
                        the provider rather than in the action row. */}
                    {disabled && (
                        <Badge
                            variant="secondary"
                            className="shrink-0 text-[10px] font-medium tracking-wide uppercase"
                        >
                            {connectLabel}
                        </Badge>
                    )}
                </div>

                {description && (
                    <p className="mt-4 line-clamp-2 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}

                {/* Both actions close the card on one line: the tutorial sits
                    left as the quiet secondary, Connect anchors the right.
                    mt-auto keeps that row on the baseline whatever length the
                    description runs to, so a grid of cards stays aligned. */}
                <div className="mt-auto flex items-center justify-between gap-3 pt-5">
                    <button
                        type="button"
                        onClick={onViewTutorial}
                        className="inline-flex min-w-0 items-center gap-1.5 rounded-sm text-xs text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        <BookOpen className="size-3.5 shrink-0" />
                        <span className="truncate">How to connect {name}</span>
                    </button>

                    {/* Solid at rest so it reads as the primary action on
                        sight, and the only click target on the card. */}
                    {!disabled && (
                        <Button
                            size="sm"
                            onClick={onConnect}
                            className="shrink-0 shadow"
                        >
                            {connectLabel}
                            <ArrowRight />
                        </Button>
                    )}
                </div>
            </div>
        </Card>
    );
}
