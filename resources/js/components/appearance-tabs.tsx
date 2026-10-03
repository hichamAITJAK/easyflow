import { RadioGroup as RadioGroupPrimitive } from 'radix-ui';
import type { HTMLAttributes } from 'react';
import { useId } from 'react';
import type { Appearance, ResolvedAppearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { useTranslation } from '@/hooks/use-translation';
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
        canvas: 'oklch(0.985 0.004 325)',
        sidebar: 'oklch(0.99 0.003 325)',
        surface: 'oklch(1 0.002 325)',
        line: 'oklch(0.93 0.006 325)',
        accent: 'oklch(0.3 0.089 339.3)',
        muted: 'oklch(0.92 0.005 325)',
    },
    dark: {
        canvas: 'oklch(0.15 0.01 325)',
        sidebar: 'oklch(0.21 0.012 325)',
        surface: 'oklch(0.19 0.012 325)',
        line: 'oklch(0.28 0.01 325)',
        accent: 'oklch(0.7 0.11 313.5)',
        muted: 'oklch(0.32 0.01 325)',
    },
};

const OPTIONS: { value: Appearance; label: string; hint: string }[] = [
    { value: 'light', label: 'Light', hint: 'Default' },
    { value: 'dark', label: 'Dark', hint: 'Easier on the eyes at night' },
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
    return <ThemeSkeleton scheme={value} />;
}

export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { t } = useTranslation();

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
                className="grid grid-cols-1 gap-3 sm:grid-cols-2"
            >
                <span id={labelId} className="sr-only">
                    {t('Theme')}
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
                                    {t(label)}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {t(hint)}
                                </span>
                            </div>
                        </RadioGroupPrimitive.Item>
                    );
                })}
            </RadioGroupPrimitive.Root>
        </div>
    );
}
