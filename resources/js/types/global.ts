import type { Auth } from './auth';

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    quote?: { message: string; author: string };
    status?: string;
    toast?: { type: 'success' | 'error'; message: string } | null;
    locale: Locale;
    translations: Record<string, string>;
};

export type Locale = 'fr' | 'en';
