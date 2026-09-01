import { RadioGroup as RadioGroupPrimitive } from 'radix-ui';
import type { HTMLAttributes } from 'react';
import { useId } from 'react';
import type { Appearance, ResolvedAppearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

type Palette = {
    readonly canvas: string;
    readonly sidebar: string;
    readonly surface: string;
    readonly line: string;
    readonly accent: string;
    readonly muted: string;
};

/**
 * The preview has to show a theme that is *not* the one currently applied —
 * a "Dark" card must read as dark while the app is in light mode. Tailwind's
 * `dark:` variant keys off the root class, so it cannot express that, and
 * neither can the theme tokens (they resolve to whichever mode is live).
 * These are the literal `:root` / `.dark` values from resources/css/app.css,
 * inlined so each card renders its own theme independently. Keep in step if
 * those tokens change.
 */
const PALETTES: Record<ResolvedAppearance, Palette> = {
    light: {
        canvas: 'oklch(0.985 0.004 260)',
        sidebar: 'oklch(0.99 0.003 260)',
        surface: 'oklch(1 0.002 260)',
        line: 'oklch(0.93 0.006 260)',
        accent: 'oklch(0.52 0.2 264)',
        muted: 'oklch(0.92 0.005 260)',
    },
    dark: {
        canvas: 'oklch(0.145 0 0)',
        sidebar: 'oklch(0.205 0 0)',
        surface: 'oklch(0.185 0 0)',
        line: 'oklch(0.28 0 0)',
        accent: 'oklch(0.55 0.19 264)',
        muted: 'oklch(0.32 0 0)',
    },
};

const OPTIONS: { value: Appearance; label: string; hint: string }[] = [
    { value: 'light', label: 'Light', hint: 'Always light' },
    { value: 'dark', label: 'Dark', hint: 'Always dark' },
    { value: 'system', label: 'System', hint: 'Match device' },
];

/**
 * Miniature of the real app shell — sidebar, header, content rows — so the
 * choice is made against the layout the user actually works in rather than
 * against an abstract swatch.
 */
function ThemeSkeleton({ scheme }: { scheme: ResolvedAppearance }) {
    const palette = PALETTES[scheme];

    return (
        <div
            aria-hidden
            className="flex h-full w-full"
            style={{ backgroundColor: palette.canvas }}
        >
            <div
                className="flex w-1/4 shrink-0 flex-col gap-1.5 border-r p-2"
                style={{
                    backgroundColor: palette.sidebar,
                    borderColor: palette.line,
                }}
            >
                <div
                    className="h-1.5 w-3/4 rounded-full"
                    style={{ backgroundColor: palette.accent }}
                />
                <div
                    className="h-1.5 w-full rounded-full"
                    style={{ backgroundColor: palette.muted }}
                />
                <div
                    className="h-1.5 w-2/3 rounded-full"
                    style={{ backgroundColor: palette.muted }}
                />
            </div>

            <div className="flex min-w-0 flex-1 flex-col gap-1.5 p-2">
                <div
                    className="h-1.5 w-1/2 rounded-full"
                    style={{ backgroundColor: palette.muted }}
                />
                <div
                    className="flex-1 rounded-sm border"
                    style={{
                        backgroundColor: palette.surface,
                        borderColor: palette.line,
                    }}
                />
            </div>
        </div>
    );
}

function ThemePreview({ value }: { value: Appearance }) {
    if (value !== 'system') {
        return <ThemeSkeleton scheme={value} />;
    }

    // Split preview: "System" is not a third look, it is whichever of the
    // other two the device asks for — showing both says that without copy.
    return (
        <div className="relative h-full w-full">
            <ThemeSkeleton scheme="light" />
            <div
                className="absolute inset-0"
                style={{ clipPath: 'polygon(100% 0, 100% 100%, 0 100%)' }}
            >
                <ThemeSkeleton scheme="dark" />
            </div>
        </div>
    );
}

export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();
    const labelId = useId();

    return (
        <div className={cn(className)} {...props}>
            <RadioGroupPrimitive.Root
                value={appearance}
                onValueChange={(value) =>
                    updateAppearance(value as Appearance)
                }
                aria-labelledby={labelId}
                className="grid grid-cols-1 gap-3 sm:grid-cols-3"
            >
                <span id={labelId} className="sr-only">
                    Theme
                </span>

                {OPTIONS.map(({ value, label, hint }) => {
                    const selected = appearance === value;

                    return (
                        <RadioGroupPrimitive.Item
                            key={value}
                            value={value}
                            className={cn(
                                'group flex flex-col overflow-hidden rounded-lg border text-left outline-none transition-colors',
                                'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                selected
                                    ? 'border-primary ring-[3px] ring-primary/20'
                                    : 'border-input hover:border-ring/60',
                            )}
                        >
                            <div
                                className={cn(
                                    'h-40 w-full overflow-hidden border-b',
                                    selected
                                        ? 'border-primary/40'
                                        : 'border-input',
                                )}
                            >
                                <ThemePreview value={value} />
                            </div>

                            <div className="flex w-full items-baseline justify-between gap-2 bg-card px-3 py-2">
                                <span className="text-sm font-medium text-foreground">
                                    {label}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {hint}
                                </span>
                            </div>
                        </RadioGroupPrimitive.Item>
                    );
                })}
            </RadioGroupPrimitive.Root>
        </div>
    );
}
