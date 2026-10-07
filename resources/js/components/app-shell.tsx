import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { AppVariant, PageProps } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
    /** Extra classes on the shell root (e.g. a per-area font). */
    className?: string;
};

export function AppShell({ children, variant = 'sidebar', className }: Props) {
    const isOpen = usePage<PageProps>().props.sidebarOpen;

    if (variant === 'header') {
        return (
            <div className={cn('flex min-h-screen w-full flex-col', className)}>
                {children}
            </div>
        );
    }

    return (
        <SidebarProvider defaultOpen={isOpen} className={className}>
            {children}
        </SidebarProvider>
    );
}
