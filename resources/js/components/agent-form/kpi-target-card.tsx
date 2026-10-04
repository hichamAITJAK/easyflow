import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { useTranslation } from '@/hooks/use-translation';

/**
 * One card for a single performance target (confirmation rate, daily order
 * quota, delivery success rate): a default-vs-custom toggle, and — once
 * customized — either a percentage slider or a plain number input, plus
 * quick-pick shortcuts for the values an owner reaches for most often.
 *
 * The three targets on ConfirmationAgentForm shared this exact shape
 * (icon + label + description + toggle + slider-or-input + quick-picks) as
 * three near-identical ~60-line blocks; this is the one component they now
 * render from, parameterized by unit/range/quick-picks per metric.
 */
export function KpiTargetCard({
    icon: Icon,
    title,
    description,
    defaultLabel,
    isCustom,
    onToggleCustom,
    value,
    onValueChange,
    quickPicks,
    unit = '%',
    inputMode = 'slider',
    min = 10,
    max = 100,
    step = 5,
    hiddenFields,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    /** e.g. "Default (80%)" shown on the toggle button when not customized. */
    defaultLabel: string;
    isCustom: boolean;
    onToggleCustom: () => void;
    value: string;
    onValueChange: (value: string) => void;
    quickPicks: string[];
    unit?: string;
    inputMode?: 'slider' | 'number';
    min?: number;
    max?: number;
    step?: number;
    /** Hidden inputs carrying this target's `targets[n][...]` fields when customized. */
    hiddenFields?: React.ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <Card className="shadow-none">
            <CardContent>
                <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                        <div className="flex items-center gap-2">
                            <Icon className="size-4 text-muted-foreground" />
                            <span className="text-sm font-semibold">
                                {title}
                            </span>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant={isCustom ? 'default' : 'outline'}
                        size="sm"
                        onClick={onToggleCustom}
                    >
                        {isCustom ? t('Custom Target') : defaultLabel}
                    </Button>
                </div>

                {isCustom && (
                    <div className="mt-4 space-y-3 border-t pt-4">
                        {inputMode === 'slider' ? (
                            <>
                                <div className="flex items-center justify-between">
                                    <Label className="text-xs">{title}</Label>
                                    <span className="text-sm font-bold text-primary">
                                        {value}
                                        {unit}
                                    </span>
                                </div>
                                <Slider
                                    value={[Number(value)]}
                                    onValueChange={([val]) =>
                                        onValueChange(String(val))
                                    }
                                    min={min}
                                    max={max}
                                    step={step}
                                />
                            </>
                        ) : (
                            <div className="flex items-center justify-between">
                                <Label className="text-xs">{title}</Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        type="number"
                                        min={min}
                                        className="h-8 w-24 text-right text-xs"
                                        value={value}
                                        onChange={(event) =>
                                            onValueChange(event.target.value)
                                        }
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        {unit}
                                    </span>
                                </div>
                            </div>
                        )}

                        <div className="flex gap-2 pt-1">
                            {quickPicks.map((pick) => (
                                <Button
                                    key={pick}
                                    type="button"
                                    variant={
                                        value === pick ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    className="h-7 px-2.5 text-xs"
                                    onClick={() => onValueChange(pick)}
                                >
                                    {pick}
                                    {inputMode === 'slider' ? unit : ''}
                                </Button>
                            ))}
                        </div>

                        {hiddenFields}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
