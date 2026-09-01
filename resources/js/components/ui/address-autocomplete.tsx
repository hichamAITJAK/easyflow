import * as React from 'react';
import { Loader2, MapPin } from 'lucide-react';
import {
    Command,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import {
    isGooglePlacesConfigured,
} from '@/lib/google-places';
import { cn } from '@/lib/utils';
import {
    useAddressAutocomplete,
    type AddressSuggestion,
    type ResolvedAddress,
} from '@/hooks/use-address-autocomplete';

/**
 * A free-text address field backed by Google Places autocomplete, styled
 * to match this app's Combobox. Falls back to a plain input (still fully
 * usable, just without suggestions) when no API key is configured or the
 * Places script fails to load — an address field must never be blocked on
 * a third-party API being reachable.
 */
export function AddressAutocomplete({
    id,
    name,
    value,
    onChange,
    onResolved,
    placeholder = 'Start typing an address…',
    required,
    className,
}: {
    id?: string;
    name?: string;
    value: string;
    onChange: (value: string) => void;
    onResolved?: (address: ResolvedAddress) => void;
    placeholder?: string;
    required?: boolean;
    className?: string;
}) {
    const configured = isGooglePlacesConfigured();
    const [open, setOpen] = React.useState(false);
    const { suggestions, loading, unavailable, resolveSuggestion } =
        useAddressAutocomplete(configured ? value : '');

    if (!configured || unavailable) {
        return (
            <Input
                id={id}
                name={name}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                required={required}
                className={className}
            />
        );
    }

    const handleSelect = async (
        suggestion: AddressSuggestion,
        fallbackLabel: string,
    ) => {
        setOpen(false);
        onChange(fallbackLabel);

        const resolved = await resolveSuggestion(suggestion);

        if (resolved) {
            onChange(resolved.formattedAddress);
            onResolved?.(resolved);
        }
    };

    return (
        <Popover open={open && suggestions.length > 0}>
            <PopoverAnchor asChild>
                <div className="relative">
                    <Input
                        id={id}
                        name={name}
                        value={value}
                        onChange={(event) => {
                            onChange(event.target.value);
                            setOpen(true);
                        }}
                        onFocus={() => setOpen(true)}
                        onBlur={() =>
                            setTimeout(() => setOpen(false), 150)
                        }
                        placeholder={placeholder}
                        required={required}
                        autoComplete="off"
                        className={cn('pr-8', className)}
                    />
                    {loading && (
                        <Loader2 className="absolute top-1/2 right-2.5 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                    )}
                </div>
            </PopoverAnchor>
            <PopoverContent
                className="w-(--radix-popover-trigger-width) p-0"
                onOpenAutoFocus={(event) => event.preventDefault()}
            >
                <Command shouldFilter={false}>
                    <CommandList>
                        <CommandGroup>
                            {suggestions.map((suggestion) => (
                                <CommandItem
                                    key={suggestion.placeId}
                                    value={suggestion.placeId}
                                    onSelect={() =>
                                        handleSelect(
                                            suggestion,
                                            [
                                                suggestion.mainText,
                                                suggestion.secondaryText,
                                            ]
                                                .filter(Boolean)
                                                .join(', '),
                                        )
                                    }
                                    className="items-start gap-2"
                                >
                                    <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    <div className="grid min-w-0">
                                        <span className="truncate">
                                            {suggestion.mainText}
                                        </span>
                                        {suggestion.secondaryText && (
                                            <span className="truncate text-xs text-muted-foreground">
                                                {suggestion.secondaryText}
                                            </span>
                                        )}
                                    </div>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
