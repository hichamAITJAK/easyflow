import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useTranslation } from '@/hooks/use-translation';
import { formatRelativeTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    ContentItem,
    ContentType,
    CreativeProduct,
    Person,
    QueueItem,
    RequestStatus,
} from '@/types/creatives';

/* ────────────────────────── palette (handoff §9) ───────────────────── */
export const C = {
    teal: '#16A08E',
    tealText: '#0C7D6F',
    orange: '#F2602F',
    orangeText: '#C24A1A',
    mustard: '#EFA22C',
    mustardText: '#A87110',
    petrol: '#468FA5',
    petrolText: '#2E7389',
    red: '#D92D20',
    redText: '#B3281D',
};

/* ────────────────────────── derived helpers ────────────────────────── */
export const typeLabel = (type: ContentType) =>
    type === 'video' ? 'Videos' : 'Statics';

export const itemsLabel = (items: ContentItem[]) =>
    items.map((i) => `${typeLabel(i.type)} ×${i.count}`).join(' + ');

export const totalCount = (items: ContentItem[]) =>
    items.reduce((s, i) => s + i.count, 0);

export const initialsOf = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p[0] ?? '')
        .join('')
        .toUpperCase();

/** "2h ago" — the queue's "when" is already the status timestamp. */
export const ago = (iso: string | null) =>
    iso ? formatRelativeTime(iso) : '—';

/** A product without a validated push for two days or more is going stale. */
export const isStale = (iso: string | null) =>
    iso !== null &&
    Date.now() - new Date(iso).getTime() >= 2 * 24 * 60 * 60 * 1000;

/* ────────────────────────── chips ───────────────────────────────────── */
export function TypeBadge({
    type,
    count,
}: {
    type: ContentType;
    count?: number;
}) {
    const video = type === 'video';

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold',
                video
                    ? 'bg-secondary text-secondary-foreground'
                    : 'bg-[#468FA5]/12 text-[#2E7389]',
            )}
        >
            {video ? '🎬' : '🖼'} {typeLabel(type)}
            {count ? ` ×${count}` : ''}
        </span>
    );
}

export function KindTag({
    kind,
    links,
}: {
    kind: 'single' | 'pack';
    links: number;
}) {
    return kind === 'pack' ? (
        <span className="rounded-md bg-secondary px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-secondary-foreground">
            PACK · {links}
        </span>
    ) : (
        <span className="rounded-md bg-muted px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-muted-foreground">
            PRODUCT
        </span>
    );
}

export function TestTag({ status }: { status: string }) {
    if (status !== 'testing') {
        return null;
    }

    return (
        <span className="rounded-md border border-dashed border-[#A87110]/60 bg-[#EFA22C]/10 px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-[#A87110]">
            🧪 TEST
        </span>
    );
}

export function RevTag({ rev }: { rev: number }) {
    return (
        <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] font-semibold text-muted-foreground">
            v{rev}
        </span>
    );
}

const STATUS_CHIP: Record<
    Exclude<RequestStatus, 'validated'>,
    { cls: string; label: string }
> = {
    sent: {
        cls: 'bg-secondary text-secondary-foreground',
        label: 'Submitted to editor',
    },
    returned: {
        cls: 'bg-[#F2602F]/10 text-[#C24A1A]',
        label: 'Returned from editor',
    },
    edits: { cls: 'bg-[#EFA22C]/12 text-[#A87110]', label: 'Needs edits' },
};

export function StatusChip({ status }: { status: RequestStatus }) {
    const { t } = useTranslation();

    if (status === 'validated') {
        return null;
    }

    const chip = STATUS_CHIP[status];

    return (
        <span
            className={cn(
                'rounded-full px-2.5 py-1 text-xs font-semibold',
                chip.cls,
            )}
        >
            {t(chip.label)}
        </span>
    );
}

export function EditorAvatars({ editors }: { editors: Person[] }) {
    if (!editors.length) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    return (
        <span className="inline-flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
            <span className="flex -space-x-1.5">
                {editors.map((e) => (
                    <span
                        key={e.id}
                        title={e.name}
                        className="flex size-6 items-center justify-center rounded-full bg-secondary text-[10px] font-semibold text-secondary-foreground ring-2 ring-card"
                    >
                        {e.initials}
                    </span>
                ))}
            </span>
            <span className="truncate">
                {editors.length > 1
                    ? editors.map((e) => e.name.split(' ')[0]).join(' + ')
                    : editors[0].name}
            </span>
        </span>
    );
}

export function Money({
    value,
    className,
}: {
    value: number;
    className?: string;
}) {
    return (
        <span className={cn('font-mono font-semibold tabular-nums', className)}>
            {value.toLocaleString()}{' '}
            <span className="font-sans text-xs font-normal text-muted-foreground">
                MAD
            </span>
        </span>
    );
}

export function EmptyCard({ children }: { children: ReactNode }) {
    return (
        <div className="col-span-full flex min-h-[200px] items-center justify-center rounded-xl border border-dashed border-border bg-card p-8 text-center text-sm text-muted-foreground">
            {children}
        </div>
    );
}

/** Pill toggle used for editors / content types in the sheets. */
export function PillToggle({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onClick}
            className={cn(
                'h-auto rounded-md px-3 py-1.5 text-sm font-medium',
                active
                    ? 'border-primary bg-secondary text-secondary-foreground hover:bg-secondary'
                    : 'text-muted-foreground',
            )}
        >
            {children}
            {active ? ' ✓' : ''}
        </Button>
    );
}

export function SectionLabel({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <p
            className={cn(
                'text-xs font-medium tracking-wide text-muted-foreground uppercase',
                className,
            )}
        >
            {children}
        </p>
    );
}

/** The right-hand panel every creatives dialog uses. */
export function SidePanel({
    open,
    onOpenChange,
    title,
    description,
    children,
    footer,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-md">
                <SheetHeader className="border-b border-border p-5 text-left">
                    <SheetTitle className="text-base">{title}</SheetTitle>
                    {description ? (
                        <SheetDescription className="text-xs">
                            {description}
                        </SheetDescription>
                    ) : (
                        <SheetDescription className="sr-only">
                            {title}
                        </SheetDescription>
                    )}
                </SheetHeader>
                <div className="flex-1 space-y-5 overflow-y-auto p-5">
                    {children}
                </div>
                {footer && (
                    <div className="space-y-3 border-t border-border p-5">
                        {footer}
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}

/** Per-creative direction list, read-only (used in detail views). */
export function DirectionsBlock({ items }: { items: ContentItem[] }) {
    const { t } = useTranslation();

    return (
        <div>
            <SectionLabel>
                {t('Requested · directions per creative')}
            </SectionLabel>
            {items.map((item) => (
                <div key={item.type} className="mt-3">
                    <div className="mb-2">
                        <TypeBadge type={item.type} count={item.count} />
                    </div>
                    <div className="space-y-2">
                        {Array.from({ length: item.count }, (_, i) => {
                            const d = (item.directions[i] ?? '').trim();

                            return (
                                <div
                                    key={i}
                                    className="flex gap-2 rounded-lg bg-muted/50 p-3 text-sm"
                                >
                                    <span className="font-mono text-xs font-bold text-muted-foreground">
                                        {item.type === 'video' ? 'V' : 'S'}
                                        {i + 1}
                                    </span>
                                    <span
                                        className={
                                            d
                                                ? ''
                                                : 'text-muted-foreground italic'
                                        }
                                    >
                                        {d || t('free style')}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

/** The one in-flight tag a product card shows: returned > edits > sent. */
export function FlightTag({
    product,
    queue,
    onGoToQueue,
}: {
    product: CreativeProduct;
    queue: QueueItem[];
    onGoToQueue: () => void;
}) {
    const { t } = useTranslation();
    const mine = queue.filter((q) => q.product.id === product.id);
    const returned = mine.find((q) => q.status === 'returned');
    const edits = mine.find((q) => q.status === 'edits');
    const sent = mine.find((q) => q.status === 'sent');

    if (returned) {
        return (
            <button
                type="button"
                onClick={onGoToQueue}
                className="mt-3 inline-flex w-fit items-center gap-1.5 rounded-full bg-[#F2602F]/10 px-2.5 py-1 text-xs font-semibold text-[#C24A1A] hover:bg-[#F2602F]/20"
            >
                ● {itemsLabel(returned.items)} {t('returned — review it →')}
            </button>
        );
    }

    if (edits) {
        return (
            <span className="mt-3 inline-flex w-fit items-center gap-1.5 rounded-full bg-[#EFA22C]/12 px-2.5 py-1 text-xs font-semibold text-[#A87110]">
                ● {t('In edits with :name', { name: edits.editor })} · v
                {edits.rev}
            </span>
        );
    }

    if (sent) {
        return (
            <span className="mt-3 inline-flex w-fit items-center gap-1.5 rounded-full bg-secondary px-2.5 py-1 text-xs font-semibold text-secondary-foreground">
                ● {itemsLabel(sent.items)}{' '}
                {t('with :name · waiting', { name: sent.editor })}
            </span>
        );
    }

    return null;
}

export { Badge };
