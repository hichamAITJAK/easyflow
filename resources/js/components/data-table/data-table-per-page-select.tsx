import { router } from '@inertiajs/react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const OPTIONS = [10, 20, 50, 100];

/**
 * Page-size picker for a backend-driven DataTable: changes the `per_page`
 * query param via a server visit, resetting back to page 1 so the current
 * page number never lands out of range for the new page size.
 */
export function DataTablePerPageSelect({
    routeUrl,
    query = {},
    value = 20,
    size = 'default',
    perPageKey = 'per_page',
    pageKey = 'page',
}: {
    routeUrl: string;
    query?: Record<string, string | undefined>;
    value?: number;
    size?: 'sm' | 'default';
    perPageKey?: string;
    pageKey?: string;
}) {
    return (
        <Select
            value={String(value)}
            onValueChange={(next) =>
                router.get(
                    routeUrl,
                    { ...query, [perPageKey]: next, [pageKey]: undefined },
                    { preserveState: true, preserveScroll: true },
                )
            }
        >
            <SelectTrigger size={size} className="w-20">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {OPTIONS.map((option) => (
                    <SelectItem key={option} value={String(option)}>
                        {option}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
