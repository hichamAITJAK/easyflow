import * as React from 'react';
import { Check, ChevronsUpDown, X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export type ComboboxOption = {
    value: string;
    label: string;
};

export function MultiCombobox({
    options,
    value,
    onChange,
    placeholder = 'Select…',
    searchPlaceholder = 'Search…',
    emptyMessage = 'No results found.',
    disabled = false,
    className,
    id,
}: {
    options: ComboboxOption[];
    value: string[];
    onChange: (value: string[]) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyMessage?: string;
    disabled?: boolean;
    className?: string;
    id?: string;
}) {
    const [open, setOpen] = React.useState(false);

    const selected = options.filter((option) => value.includes(option.value));

    const toggle = (optionValue: string) => {
        onChange(
            value.includes(optionValue)
                ? value.filter((v) => v !== optionValue)
                : [...value, optionValue],
        );
    };

    const remove = (optionValue: string) => {
        onChange(value.filter((v) => v !== optionValue));
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled}
                    className={cn(
                        'w-full justify-between gap-2 font-normal',
                        selected.length === 0 && 'text-muted-foreground',
                        className,
                    )}
                >
                    {selected.length > 0 ? (
                        <span
                            className="flex w-0 flex-1 items-center gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                            onWheel={(event) => {
                                if (event.deltaY === 0) return;
                                event.currentTarget.scrollLeft += event.deltaY;
                            }}
                        >
                            {selected.map((option) => (
                                <Badge
                                    key={option.value}
                                    variant="outline"
                                    className="shrink-0 gap-1 pr-1 font-normal bg-muted"
                                >
                                    {option.label}
                                    <span
                                        role="button"
                                        tabIndex={0}
                                        aria-label={`Remove ${option.label}`}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            event.stopPropagation();
                                            remove(option.value);
                                        }}
                                        onKeyDown={(event) => {
                                            if (
                                                event.key === 'Enter' ||
                                                event.key === ' '
                                            ) {
                                                event.preventDefault();
                                                event.stopPropagation();
                                                remove(option.value);
                                            }
                                        }}
                                        className="rounded-full p-0.5 hover:bg-muted-foreground/20"
                                    >
                                        <X className="size-3" />
                                    </span>
                                </Badge>
                            ))}
                        </span>
                    ) : (
                        placeholder
                    )}
                    <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-(--radix-popover-trigger-width) p-0">
                <Command>
                    <CommandInput placeholder={searchPlaceholder} />
                    <CommandList>
                        <CommandEmpty>{emptyMessage}</CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => (
                                <CommandItem
                                    key={option.value}
                                    value={option.label}
                                    onSelect={() => toggle(option.value)}
                                >
                                    <Check
                                        className={cn(
                                            'mr-2 size-4',
                                            value.includes(option.value)
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                    {option.label}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
