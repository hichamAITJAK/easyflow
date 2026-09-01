import type { Auth } from './auth';
import type { SharedSubscriptionState } from './subscription';

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    quote?: { message: string; author: string };
    status?: string;
    toast?: { type: 'success' | 'error'; message: string } | null;
    subscription?: SharedSubscriptionState | null;
};
