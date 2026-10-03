import type { LucideIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { formatCompactNumber, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

const ACCENTS = {
    default: {
        text: '',
        chip: 'bg-primary/10 text-primary',
    },
    success: {
        text: 'text-success',
        chip: 'bg-success/10 text-success',
    },
    destructive: {
        text: 'text-destructive',
        chip: 'bg-destructive/10 text-destructive',
    },
};

function DeltaPill({ delta, good }: { delta: number; good: boolean }) {
    // Zero change is neither a win nor a loss — a green "+0.0%" would
    // celebrate standing still.
    if (delta === 0) {
        return (
            <Badge
                variant="outline"
                className="border-transparent bg-muted tabular-nums text-muted-foreground"
            >
                0.0%
            </Badge>
        );
    }

    return (
        <Badge
            variant="outline"
            className={cn(
                'border-transparent tabular-nums',
                good
                    ? 'bg-success/10 text-success'
                    : 'bg-destructive/10 text-destructive',
            )}
        >
            {delta > 0 ? '+' : ''}
            {delta.toFixed(1)}%
        </Badge>
    );
}

/**
 * One tile template for every dashboard metric: optional icon chip, label,
 * optional caption, value, optional period-over-period delta. Covers a bare
 * count (agent dashboard) up through a captioned money figure with a trend
 * pill (admin dashboard) without switching layouts between them.
 *
 * The trailing slot holds either a delta or a rate, never both — they'd
 * read as one compound figure crammed into a tile that has room for one
 * number. Delta wins when a caller passes both.
 */
export function StatTile({
    label,
    value,
    caption,
    accent,
    icon: Icon,
    delta,
    higherIsBetter = true,
    exactValue,
    captionAccent,
    rate,
}: {
    label: string;
    value: string | number;
    caption?: string;
    accent?: 'success' | 'destructive' | 'default';
    icon?: LucideIcon;
    /** Period-over-period % change. Omit when the tile has no trend to show. */
    delta?: number;
    /** Flip so a rise reads as bad — cancellations, returns, refunds. */
    higherIsBetter?: boolean;
    /** Full-precision value for a pre-formatted compact string (e.g.
     *  "152,300 MAD" behind "152.3K MAD") — hover title + sr-only text, so
     *  keyboard and screen-reader users get the exact figure too. */
    exactValue?: string;
    /** Tint the caption when it carries a judgment (e.g. a settlement
     *  shortfall) rather than a neutral description. */
    captionAccent?: 'success' | 'destructive';
    /**
     * This count as a share of its own denominator, e.g. "82%" of assigned
     * orders confirmed. Rendered as a neutral chip, never the green/red of
     * DeltaPill: a rate is a standing ratio, not a movement, and colouring
     * it would assert a judgment the tile has no target to justify. Null
     * renders nothing — no denominator is not 0%.
     */
    rate?: number | null;
}) {
    const { t } = useTranslation();

    const { text, chip } = ACCENTS[accent ?? 'default'];
    const deltaIsGood =
        delta === undefined || delta === 0 || delta > 0 === higherIsBetter;
    const valueTitle = exactValue ?? (typeof value === 'number' ? formatNumber(value) : undefined);
    const valueText = typeof value === 'number' ? formatCompactNumber(value) : value;

    return (
        <Card className="gap-0 p-4 shadow-none">
            {Icon && (
                <div
                    className={cn(
                        'mb-4 flex size-10 items-center justify-center rounded-lg',
                        chip,
                    )}
                >
                    <Icon className="size-5" />
                </div>
            )}

            <p className="text-sm font-semibold">{label}</p>
            {caption && (
                <p
                    className={cn(
                        'mt-0.5 text-xs text-muted-foreground',
                        captionAccent === 'destructive' && 'text-destructive',
                        captionAccent === 'success' && 'text-success',
                    )}
                >
                    {caption}
                </p>
            )}

            <div className="mt-auto flex items-end justify-between gap-2 pt-4">
                <span
                    className={cn('text-2xl font-semibold tabular-nums', text)}
                    title={valueTitle}
                >
                    {valueText}
                    {valueTitle && valueTitle !== String(valueText) && (
                        <span className="sr-only">
                            {t(', exactly :value', { value: valueTitle })}
                        </span>
                    )}
                </span>

                {delta !== undefined ? (
                    <DeltaPill delta={delta} good={deltaIsGood} />
                ) : ( rate !== undefined && rate !== null && (
                        <Badge
                            variant="outline"
                            className="border-transparent bg-muted tabular-nums text-muted-foreground"
                        >
                            {rate}%
                        </Badge>
                    )
                )}
            </div>
        </Card>
    );
}
