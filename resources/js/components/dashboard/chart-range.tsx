import { useState } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const RANGES = [
    { value: '7', label: 'Last 7 days' },
    { value: '14', label: 'Last 14 days' },
    { value: '30', label: 'Last 30 days' },
    { value: '90', label: 'Last 3 months' },
];

/**
 * Client-side range narrowing for a dashboard chart, shared by every card
 * that offers a range picker so they can't drift apart.
 *
 * Only ranges the loaded data can actually cover are offered — the server
 * decides the period, so offering "last 3 months" against 30 days of data
 * would render a window that is mostly empty and read as a collapse in
 * volume rather than as missing data. Defaults to the widest range on offer,
 * i.e. everything the server sent.
 */
export function useChartRange(dataLength: number) {
    const available = RANGES.filter(
        (range, index) =>
            Number(range.value) <= dataLength ||
            // Always keep the smallest range so the picker is never empty.
            index === 0,
    );

    const [range, setRange] = useState<string>(
        available[available.length - 1].value,
    );

    return {
        /** Trim a date-ascending series to the selected window. */
        take: <T,>(data: T[]): T[] => data.slice(-Number(range)),
        days: Math.min(Number(range), dataLength),
        selectProps: { value: range, onValueChange: setRange, available },
    };
}

export function ChartRangeSelect({
    value,
    onValueChange,
    available,
}: ReturnType<typeof useChartRange>['selectProps']) {
    if (available.length < 2) {
        return null;
    }

    return (
        <Select value={value} onValueChange={onValueChange}>
            <SelectTrigger
                className="w-[160px] rounded-lg sm:ml-auto"
                aria-label="Select a range"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent className="rounded-xl">
                {available.map((option) => (
                    <SelectItem
                        key={option.value}
                        value={option.value}
                        className="rounded-lg"
                    >
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
