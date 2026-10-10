import { RotateCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

import { useTranslation } from '@/hooks/use-translation';
/**
 * Icon-only reset control for a DataTable's filter row. Render conditionally
 * on `hasActiveFilters` from useTableFilters, so it only appears once a
 * filter has actually been changed from its default.
 */
export function DataTableResetFiltersButton({
    onReset,
}: {
    onReset: () => void;
}) {
    const { t } = useTranslation();

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-9 rounded-md bg-card shadow-xs hover:bg-accent hover:text-accent-foreground"
                    onClick={onReset}
                    aria-label={t('Reset filters')}
                >
                    <RotateCcw />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{t('Reset filters')}</TooltipContent>
        </Tooltip>
    );
}
