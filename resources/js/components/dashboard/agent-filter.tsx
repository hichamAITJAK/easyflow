import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import { useTranslation } from '@/hooks/use-translation';
const ALL = '__all__';

export type AgentOption = { id: number | string; name: string };

/**
 * Per-card agent scope picker. Lives in the header of charts whose metric
 * is attributable to a single agent (rate trend, funnel, reasons, best
 * hour) — a page-global agent filter would be wrong for charts like
 * "orders by store" that aren't agent-scoped facts.
 */
export function AgentFilter({
    agents,
    value,
    onChange,
    placeholder = 'All agents',
    allLabel = 'All agents',
    ariaLabel = 'Filter by agent',
}: {
    agents: AgentOption[];
    value: string | undefined;
    onChange: (value: string | undefined) => void;
    /** Trigger text while no agent is picked (e.g. the default agent's name). */
    placeholder?: string;
    /** Label of the unset option — "All agents", "Best agent", … */
    allLabel?: string;
    /** Name THIS instance's job — several agent filters share a page, and a
     *  screen-reader rotor can't tell identical labels apart. */
    ariaLabel?: string;
}) {
    const { t } = useTranslation();

    return (
        <Select
            value={value ?? ALL}
            onValueChange={(next) => onChange(next === ALL ? undefined : next)}
        >
            <SelectTrigger
                size="sm"
                className="w-[140px] rounded-lg"
                aria-label={t(ariaLabel)}
            >
                <SelectValue placeholder={t(placeholder)} />
            </SelectTrigger>
            <SelectContent className="rounded-xl">
                <SelectItem value={ALL} className="rounded-lg">
                    {t(allLabel)}
                </SelectItem>
                {agents.map((agent) => (
                    <SelectItem
                        key={agent.id}
                        value={String(agent.id)}
                        className="rounded-lg"
                    >
                        {agent.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
