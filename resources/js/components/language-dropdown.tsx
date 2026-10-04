import { router, usePage } from '@inertiajs/react';
import { Check, Languages } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/hooks/use-translation';
import { update as updateLocale } from '@/routes/locale';
import type { Locale, PageProps } from '@/types';

/**
 * Labels stay in their own language on purpose: someone looking for
 * French should not have to read "French" in English to find it.
 */
const OPTIONS: { value: Locale; label: string }[] = [
    { value: 'fr', label: 'Français' },
    { value: 'en', label: 'English' },
];

export function LanguageDropdown() {
    const { locale } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [pending, setPending] = useState(false);

    const choose = (value: Locale) => {
        if (value === locale || pending) {
            return;
        }

        router.post(
            updateLocale.url(),
            { locale: value },
            {
                preserveScroll: true,
                onStart: () => setPending(true),
                onFinish: () => setPending(false),
            },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-9"
                    disabled={pending}
                >
                    <Languages className="size-5 opacity-80" />
                    <span className="sr-only">{t('Change language')}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-36">
                {OPTIONS.map(({ value, label }) => (
                    <DropdownMenuItem
                        key={value}
                        onSelect={() => choose(value)}
                    >
                        {label}
                        {locale === value && (
                            <Check className="ml-auto size-4" />
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
