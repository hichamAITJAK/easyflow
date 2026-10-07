import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { getVisibleNavItems } from '@/config/nav-items';
import { dashboard } from '@/routes';
import type { PageProps } from '@/types';

/*
  The shell sidebar per the approved dashboard reference: the mark alone
  at the top (slowly turning), links in four titled sections with the
  current one filled plum, the signed-in user at the foot. Collapses to
  icons on desktop, slides in as a drawer on mobile.
*/
export function AppSidebar() {
    const { auth } = usePage<PageProps>().props;
    const visibleNavItems = getVisibleNavItems(auth.user?.role);

    return (
        <Sidebar
            collapsible="icon"
            className="border-r border-border [&_[data-sidebar=sidebar]]:bg-background"
        >
            <SidebarHeader className="flex h-16 shrink-0 items-center justify-center p-0">
                <Link
                    href={dashboard()}
                    prefetch
                    className="flex items-center justify-center rounded-lg"
                >
                    {/* Shrinks to fit the icon rail when collapsed. */}
                    <AppLogoIcon className="size-12 shrink-0 rounded-lg group-data-[collapsible=icon]:size-9 motion-safe:animate-logo-spin" />
                </Link>
            </SidebarHeader>

            <SidebarContent className="pb-4">
                <NavMain items={visibleNavItems} />
            </SidebarContent>

            <SidebarFooter className="border-t border-border p-2.5">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
