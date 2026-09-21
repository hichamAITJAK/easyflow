import { createInertiaApp } from '@inertiajs/react';
import posthog from 'posthog-js';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { installMethodSpoofing } from '@/lib/method-spoofing';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Before createInertiaApp, so no page component can fire a visit through
// the unwrapped router.
installMethodSpoofing();

if (import.meta.env.VITE_POSTHOG_KEY && import.meta.env.VITE_POSTHOG_DISABLED === "false") {
    posthog.init(import.meta.env.VITE_POSTHOG_KEY as string, {
        api_host: import.meta.env.VITE_POSTHOG_HOST as string || 'https://us.i.posthog.com',
        defaults: '2026-05-30',
    });
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('errors/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            // SettingsLayout picks its own outer shell (tenant vs platform)
            // from the authenticated role, so no shell is stacked here.
            case name.startsWith('settings/'):
                return SettingsLayout;
            // Full-screen decision moment — deliberately outside the app
            // shell so a blocked business isn't teased with dead nav.
            case name === 'subscription/blocked':
                return null;
            case name.startsWith('super-admin/'):
                return null;
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: 'oklch(0.52 0.27 280.05)',
        showSpinner: false,
    },
});

// This will set light / dark mode on load...
initializeTheme();
