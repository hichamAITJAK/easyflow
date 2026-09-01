import { Check } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function ChoiceCard({
    selected,
    onSelect,
    title,
    description,
    accent = 'primary',
}: {
    selected: boolean;
    onSelect: () => void;
    title: ReactNode;
    description: ReactNode;
    accent?: 'primary' | 'amber';
}) {
    return (
        <div
            role="button"
            tabIndex={0}
            aria-pressed={selected}
            onClick={onSelect}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onSelect();
                }
            }}
            className={cn(
                'relative flex cursor-pointer flex-col justify-between rounded-md border p-4 transition-colors outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                selected
                    ? accent === 'amber'
                        ? 'border-amber-500 bg-amber-500/5 dark:border-amber-400'
                        : 'border-primary bg-primary/5'
                    : accent === 'amber'
                      ? 'hover:border-amber-500/50'
                      : 'hover:border-primary/50',
            )}
        >
            <div className="space-y-1.5">
                <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-medium">{title}</span>
                    {selected && (
                        <Check
                            className={cn(
                                'size-4 shrink-0',
                                accent === 'amber'
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : 'text-primary',
                            )}
                        />
                    )}
                </div>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}
