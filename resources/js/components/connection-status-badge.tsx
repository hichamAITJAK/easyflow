import { AlertTriangle, Clock } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';

/**
 * The three states any connected integration can be in, whether it's an
 * e-commerce store or a delivery courier. Stores and couriers use different
 * words on the wire ('connected'/'failed'/'pending' vs 'active'/'unverified'),
 * so each list maps its own vocabulary onto this shared shape rather than
 * this component learning both.
 */
export type ConnectionTone = 'live' | 'pending' | 'warning' | 'error';

/**
 * A live connection's dot animates via the same conbird-flow keyframe used
 * elsewhere for "data now flowing" moments (app.css) — a literal signal that
 * the integration is working, not decoration.
 */
export function ConnectionStatusBadge({
    tone,
    children,
}: {
    tone: ConnectionTone;
    children: ReactNode;
}) {
    if (tone === 'live') {
        return (
            <Badge
                variant="outline"
                className="gap-1.5 border-success/30 bg-success/10 text-success"
            >
                <span className="relative flex size-2">
                    <span className="absolute inline-flex size-full animate-[conbird-flow_2s_ease-in-out_infinite] rounded-full bg-success motion-reduce:hidden" />
                    <span className="relative inline-flex size-2 rounded-full bg-success" />
                </span>
                {children}
            </Badge>
        );
    }

    if (tone === 'error') {
        return (
            <Badge
                variant="outline"
                className="gap-1.5 border-destructive/30 bg-destructive/10 text-destructive"
            >
                <AlertTriangle className="size-3" />
                {children}
            </Badge>
        );
    }

    if (tone === 'warning') {
        return (
            <Badge
                variant="outline"
                className="gap-1.5 border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400"
            >
                <AlertTriangle className="size-3" />
                {children}
            </Badge>
        );
    }

    // Work still in progress is not a problem yet — a store mid-handshake
    // stays honestly neutral rather than borrowing warning colour it hasn't
    // earned.
    return (
        <Badge variant="outline" className="gap-1.5 text-muted-foreground">
            <Clock className="size-3" />
            {children}
        </Badge>
    );
}
