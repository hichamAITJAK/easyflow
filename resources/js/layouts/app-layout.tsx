import { usePage } from '@inertiajs/react';
import posthog from 'posthog-js';
import { useEffect } from 'react';
import { toast } from 'sonner';
import AppHeaderLayout from '@/layouts/app/app-header-layout';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem, PageProps } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { auth, toast: flashedToast } = usePage<PageProps>().props;
    // Agents work from their phones: a top bar plus a bottom tab bar
    // instead of the admin sidebar.
    const AppLayoutTemplate =
        auth.user?.role === 'confirmation_agent' ||
        auth.user?.role === 'fulfilment_agent'
            ? AppHeaderLayout
            : AppSidebarLayout;

    // Identify the authenticated user with PostHog on mount and when the
    // user changes; reset on unmount (logout navigates away from AppLayout).
    useEffect(() => {
        if (!auth.user) {
            return;
        }

        posthog.identify(String(auth.user.id), {
            email: auth.user.email,
            name: auth.user.name,
            role: auth.user.role,
            business_id: auth.user.business_id,
        });

        return () => {
            posthog.reset();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [auth.user?.id]);

    // Server actions (create/update/delete/sync, etc.) flash a one-shot
    // toast payload via Inertia::flash('toast', ...); this is the one place
    // that turns it into a visible sonner toast after the post-action redirect.
    useEffect(() => {
        if (!flashedToast) {
            return;
        }

        if (flashedToast.type === 'error') {
            toast.error(flashedToast.message);
        } else {
            toast.success(flashedToast.message);
        }
    }, [flashedToast]);

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
        </AppLayoutTemplate>
    );
}
