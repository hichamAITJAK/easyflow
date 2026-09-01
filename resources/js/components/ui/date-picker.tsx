import { format } from 'date-fns';
import { CalendarIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * shadcn's date picker recipe (Calendar + Popover), adapted to work off a
 * plain `yyyy-MM-dd` string so it drops straight into query-string filters.
 */
export function DatePicker({
    value,
    onChange,
    placeholder = 'Pick a date',
    className,
    id,
}: {
    value?: string;
    onChange: (value: string | undefined) => void;
    placeholder?: string;
    className?: string;
    /** Forwarded to the trigger button so a <Label htmlFor> can target it. */
    id?: string;
}) {
    const date = value ? new Date(`${value}T00:00:00`) : undefined;

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    id={id}
                    variant="outline"
                    className={cn(
                        'justify-start text-left font-normal',
                        !date && 'text-muted-foreground',
                        className,
                    )}
                >
                    <CalendarIcon />
                    {date ? format(date, 'PPP') : <span>{placeholder}</span>}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start">
                <Calendar
                    mode="single"
                    selected={date}
                    onSelect={(next) =>
                        onChange(next ? format(next, 'yyyy-MM-dd') : undefined)
                    }
                />
            </PopoverContent>
        </Popover>
    );
}
