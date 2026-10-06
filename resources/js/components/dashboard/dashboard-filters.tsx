import { CalendarIcon } from 'lucide-react';
import { DataTableResetFiltersButton } from '@/components/data-table/data-table-reset-filters-button';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Label } from '@/components/ui/label';
import { MultiCombobox } from '@/components/ui/multi-combobox';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import { formatDateRange } from '@/lib/format';

export type SimpleOption = { id: number; name: string };

export type DashboardFilterValues = {
    /** Comma-separated store ids — query-string friendly. */
    store_ids?: string;
    period?: string;
    /** Y-m-d bounds, read only when period is CUSTOM_PERIOD. */
    date_from?: string;
    date_to?: string;
    /** Per-card agent scope for the Rates card, not a page-level filter. */
    agent_id?: string;
};

export const CUSTOM_PERIOD = 'custom';

/**
 * Presets first, because an admin usually checks "how are we doing today /
 * this week / this month" — the custom range is the escape hatch for the
 * spans a preset can't name (a campaign week, a supplier's billing cycle).
 * The default (unset) is the server's 30-day window.
 */
export type PeriodOption = { value: string; label: string };

const PERIODS: PeriodOption[] = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: 'this_month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: '90d', label: 'Last 3 months' },
    { value: CUSTOM_PERIOD, label: 'Custom range…' },
];

export const DEFAULT_PERIOD = '30d';

/** Y-m-d in the viewer's own zone. toISOString() would convert to UTC and
 *  shift the day backwards for anyone east of it, so picking "today" could
 *  send yesterday. */
function toDateParam(date: Date): string {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

/** Parse a Y-m-d param as a local calendar day (new Date('2026-01-05')
 *  would be parsed as UTC midnight and render as the 4th west of UTC). */
function fromDateParam(value: string | undefined): Date | undefined {
    if (!value) {
        return undefined;
    }

    const [year, month, day] = value.split('-').map(Number);

    if (!year || !month || !day) {
        return undefined;
    }

    return new Date(year, month - 1, day);
}

/**
 * Period preset select plus, when "custom" is picked, the date-range
 * popover. Shared by the admin and agent dashboards; `periods` lets a
 * page offer a shorter list of presets. Works on any filter shape that
 * carries period/date_from/date_to.
 */
export function PeriodFilter({
    draft,
    onChange,
    periods = PERIODS,
    idPrefix = 'dashboard',
}: {
    draft: Pick<DashboardFilterValues, 'period' | 'date_from' | 'date_to'>;
    onChange: (
        next: Partial<
            Pick<DashboardFilterValues, 'period' | 'date_from' | 'date_to'>
        >,
    ) => void;
    periods?: PeriodOption[];
    idPrefix?: string;
}) {
    const { t } = useTranslation();

    const isCustom = draft.period === CUSTOM_PERIOD;
    const rangeFrom = fromDateParam(draft.date_from);
    const rangeTo = fromDateParam(draft.date_to);

    const rangeLabel =
        draft.date_from && draft.date_to
            ? formatDateRange(draft.date_from, draft.date_to)
            : t('Pick dates');

    return (
        <>
            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-period-filter`}>
                    {t('Period')}
                </Label>
                <Select
                    value={draft.period ?? DEFAULT_PERIOD}
                    onValueChange={(value) =>
                        onChange({
                            period:
                                value === DEFAULT_PERIOD ? undefined : value,
                            // Leaving custom drops the dates with it, so a
                            // stale range can't linger in the URL and
                            // reappear the next time custom is picked.
                            ...(value === CUSTOM_PERIOD
                                ? {}
                                : { date_from: undefined, date_to: undefined }),
                        })
                    }
                >
                    <SelectTrigger
                        id={`${idPrefix}-period-filter`}
                        className="w-40"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {periods.map((period) => (
                            <SelectItem key={period.value} value={period.value}>
                                {t(period.label)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {isCustom && (
                <div className="grid gap-1.5">
                    <Label htmlFor={`${idPrefix}-range-filter`}>
                        {t('Dates')}
                    </Label>
                    <Popover>
                        <PopoverTrigger asChild>
                            <Button
                                id={`${idPrefix}-range-filter`}
                                variant="outline"
                                className="w-56 justify-start font-normal"
                            >
                                <CalendarIcon />
                                {rangeLabel}
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent className="w-auto p-0" align="start">
                            <Calendar
                                mode="range"
                                autoFocus
                                defaultMonth={rangeFrom}
                                selected={
                                    rangeFrom
                                        ? { from: rangeFrom, to: rangeTo }
                                        : undefined
                                }
                                // Future days hold no stats, so offering
                                // them would only produce empty charts.
                                disabled={{ after: new Date() }}
                                onSelect={(range) =>
                                    onChange({
                                        date_from: range?.from
                                            ? toDateParam(range.from)
                                            : undefined,
                                        // A single click sets only `from`;
                                        // mirroring it as `to` keeps the
                                        // request valid (a one-day window)
                                        // instead of silently falling back
                                        // to the 30-day default.
                                        date_to: range?.to
                                            ? toDateParam(range.to)
                                            : range?.from
                                              ? toDateParam(range.from)
                                              : undefined,
                                    })
                                }
                                numberOfMonths={2}
                            />
                        </PopoverContent>
                    </Popover>
                </div>
            )}
        </>
    );
}

/**
 * Page-level dashboard scope: stores (multi) + period preset. Deliberately
 * no agent here — agent is a per-chart scope (see AgentFilter), because
 * most business-wide widgets aren't agent-attributable facts.
 */
export function DashboardFilters({
    stores,
    draft,
    onChange,
    onReset,
    hasActiveFilters,
}: {
    stores: SimpleOption[];
    draft: DashboardFilterValues;
    onChange: (next: Partial<DashboardFilterValues>) => void;
    onReset: () => void;
    hasActiveFilters: boolean;
}) {
    const { t } = useTranslation();

    const selectedStores = draft.store_ids ? draft.store_ids.split(',') : [];

    return (
        <div className="flex flex-wrap items-end gap-3">
            <div className="grid gap-1.5">
                <Label htmlFor="dashboard-store-filter">{t('Stores')}</Label>
                <MultiCombobox
                    id="dashboard-store-filter"
                    className="w-52"
                    options={stores.map((store) => ({
                        value: String(store.id),
                        label: store.name,
                    }))}
                    value={selectedStores}
                    onChange={(next) =>
                        onChange({
                            store_ids:
                                next.length > 0 ? next.join(',') : undefined,
                        })
                    }
                    placeholder={t('All stores')}
                    searchPlaceholder="Search stores…"
                    emptyMessage={t('No stores found.')}
                />
            </div>

            <PeriodFilter draft={draft} onChange={onChange} />

            {hasActiveFilters && (
                <DataTableResetFiltersButton onReset={onReset} />
            )}
        </div>
    );
}
