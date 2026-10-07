import { Link } from '@inertiajs/react';
import { Building2, ShieldCheck, Store, Truck } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { AppShell } from '@/components/app-shell';
import { AppearanceDropdown } from '@/components/appearance-dropdown';
import { LanguageDropdown } from '@/components/language-dropdown';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Badge } from '@/components/ui/badge';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarInset,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { useTranslation } from '@/hooks/use-translation';
import { index as businessesIndex } from '@/routes/super-admin/businesses';
import { index as couriersIndex } from '@/routes/super-admin/couriers';
import { index as platformsIndex } from '@/routes/super-admin/platforms';
import type { NavItem } from '@/types';

const navItems: NavItem[] = [
    {
        title: 'Businesses',
        href: businessesIndex(),
        icon: Building2,
    },
    {
        title: 'Platforms',
        href: platformsIndex(),
        icon: Store,
    },
    {
        title: 'Couriers',
        href: couriersIndex(),
        icon: Truck,
    },
    // Queue is hidden from the nav for now, not removed: the route and page
    // still exist at /super-admin/queue. Restore by uncommenting and
    // re-adding the `queueIndex` and `ListChecks` imports.
    // {
    //     title: 'Queue',
    //     href: queueIndex(),
    //     icon: ListChecks,
    // },
];

function SuperAdminSidebar() {
    return (
        <Sidebar
            collapsible="icon"
            className="border-r border-border [&_[data-sidebar=sidebar]]:bg-background"
        >
            <SidebarHeader className="flex h-16 shrink-0 items-center justify-center p-0">
                <Link
                    href={businessesIndex()}
                    prefetch
                    className="flex items-center justify-center rounded-lg"
                >
                    <AppLogoIcon className="size-12 shrink-0 rounded-lg group-data-[collapsible=icon]:size-9 motion-safe:animate-logo-spin" />
                </Link>
            </SidebarHeader>

            <SidebarContent className="pb-4">
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter className="border-t border-border p-2.5">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

/**
 * Dedicated shell for the platform-level super admin surface — deliberately
 * not the tenant AppSidebar, so it never visually reads as part of a
 * business's workspace: no tenant nav items, and the header carries a
 * "Platform" marker instead of a workspace identity.
 */
export default function SuperAdminLayout({ children }: PropsWithChildren) {
    const { t } = useTranslation();

    return (
        // Super admin reads in Sora, 14px medium, unlike the tenant app.
        <AppShell variant="sidebar" className="font-sora text-sm font-medium">
            <SuperAdminSidebar />
            <SidebarInset className="overflow-x-hidden">
                <header className="flex h-12 shrink-0 items-center gap-2 border-b border-border bg-background px-4">
                    <SidebarTrigger className="size-7" />

                    <Badge
                        variant="outline"
                        className="gap-1.5 border-amber-500/30 bg-amber-500/10 font-medium text-amber-600 dark:text-amber-400"
                    >
                        <ShieldCheck className="size-3" />
                        {t('Super Admin')}
                    </Badge>

                    <div className="ml-auto flex items-center gap-1">
                        <LanguageDropdown />
                        <AppearanceDropdown />
                    </div>
                </header>

                <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8 md:px-6">
                    {children}
                </main>
            </SidebarInset>
        </AppShell>
    );
}
