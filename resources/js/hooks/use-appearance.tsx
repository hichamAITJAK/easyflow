import { useSyncExternalStore } from 'react';

export type Appearance = 'light' | 'dark';

/**
 * Kept as an alias so existing call sites that distinguished the stored
 * choice from the rendered one keep compiling. With no "system" option the
 * two are always the same value.
 */
export type ResolvedAppearance = Appearance;

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly updateAppearance: (mode: Appearance) => void;
};

/**
 * Light is the default and the only automatic choice. There is no
 * "match the device" mode: the app is used in shops and warehouses under
 * whatever light is there, and a theme that flips on its own because the
 * phone hit sunset is a surprise, not a feature. Dark stays available as a
 * deliberate choice.
 */
const DEFAULT_APPEARANCE: Appearance = 'light';

const listeners = new Set<() => void>();
let currentAppearance: Appearance = DEFAULT_APPEARANCE;

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const isAppearance = (value: unknown): value is Appearance =>
    value === 'light' || value === 'dark';

/**
 * Anything that is not a known value — including the retired "system"
 * choice a returning browser may still hold — resolves to the default.
 */
const getStoredAppearance = (): Appearance => {
    if (typeof window === 'undefined') {
        return DEFAULT_APPEARANCE;
    }

    const stored = localStorage.getItem('appearance');

    return isAppearance(stored) ? stored : DEFAULT_APPEARANCE;
};

const applyTheme = (appearance: Appearance): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const isDark = appearance === 'dark';

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    currentAppearance = getStoredAppearance();

    // Write back so a stale "system" value is replaced once, and the cookie
    // the server reads for the first paint agrees with what runs here.
    localStorage.setItem('appearance', currentAppearance);
    setCookie('appearance', currentAppearance);

    applyTheme(currentAppearance);
}

export function useAppearance(): UseAppearanceReturn {
    const appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => DEFAULT_APPEARANCE,
    );

    const updateAppearance = (mode: Appearance): void => {
        currentAppearance = mode;

        localStorage.setItem('appearance', mode);
        setCookie('appearance', mode);

        applyTheme(mode);
        notify();
    };

    return { appearance, resolvedAppearance: appearance, updateAppearance } as const;
}
