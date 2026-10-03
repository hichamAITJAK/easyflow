import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { createTranslator } from '@/lib/i18n';
import type { Locale, PageProps } from '@/types';

/**
 * `t('Log in')` → the current language's string, or the English key when
 * none exists. Reads the strings Inertia shares on every page, so a
 * language change needs no reload beyond the visit that made it.
 */
export function useTranslation() {
    const { translations, locale } = usePage<PageProps>().props;

    const t = useMemo(() => createTranslator(translations), [translations]);

    return { t, locale: locale as Locale };
}
