import { Check, Languages } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type LanguageCode = 'en' | 'fr' | 'ar';

const options: { value: LanguageCode; label: string; flag: string }[] = [
    { value: 'en', label: 'English', flag: '🇬🇧' },
    { value: 'fr', label: 'Français', flag: '🇫🇷' },
    { value: 'ar', label: 'العربية', flag: '🇲🇦' },
];

/**
 * Display-only language switcher — no translation backend wired up yet,
 * selection is kept in local state only.
 */
export function LanguageDropdown() {
    const [language, setLanguage] = useState<LanguageCode>('en');

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-9">
                    <Languages className="size-5 opacity-80" />
                    <span className="sr-only">Change language</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-36">
                {options.map(({ value, label, flag }) => (
                    <DropdownMenuItem
                        key={value}
                        onSelect={() => setLanguage(value)}
                    >
                        <span aria-hidden="true">{flag}</span>
                        {label}
                        {language === value && (
                            <Check className="ml-auto size-4" />
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
