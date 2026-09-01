import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';

export function SelectableCard({
    title,
    description,
    logoUrl,
    selected = false,
    disabled = false,
    disabledLabel = 'Coming soon',
    onClick,
}: {
    title: string;
    description?: string | null;
    logoUrl?: string | null;
    selected?: boolean;
    disabled?: boolean;
    disabledLabel?: string;
    onClick?: () => void;
}) {
    const [logoFailed, setLogoFailed] = useState(false);
    const showLogo = logoUrl && !logoFailed;

    return (
        <Card
            role="button"
            tabIndex={disabled ? -1 : 0}
            aria-disabled={disabled}
            aria-pressed={selected}
            onClick={disabled ? undefined : onClick}
            onKeyDown={(event) => {
                if (disabled) {
                    return;
                }

                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onClick?.();
                }
            }}
            className={cn(
                'cursor-pointer py-4 transition-colors',
                selected && 'border-primary ring-2 ring-primary',
                disabled && 'cursor-not-allowed opacity-60',
            )}
        >
            <CardHeader className="gap-3">
                <div className="flex items-center justify-between">
                    {showLogo ? (
                        <img
                            src={logoUrl}
                            alt={title}
                            className="h-8 w-auto object-contain"
                            onError={() => setLogoFailed(true)}
                        />
                    ) : (
                        <CardTitle>{title}</CardTitle>
                    )}
                    {disabled && (
                        <Badge variant="outline">{disabledLabel}</Badge>
                    )}
                </div>
                {showLogo && <CardTitle>{title}</CardTitle>}
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
            </CardHeader>
        </Card>
    );
}
