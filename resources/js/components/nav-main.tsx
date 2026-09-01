import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    /**
     * A nav item stays lit on its own sub-pages ("/stores" on
     * "/stores/create"), so a merchant deep in a flow can still see which
     * section they're in.
     *
     * Nested siblings are the exception: "/customers/blacklist" is its own
     * nav item, so "/customers" must not also light up there. Whenever a
     * more specific item exists for the current URL, only that one wins.
     */
    const isActive = (item: NavItem): boolean => {
        if (isCurrentUrl(item.href)) {
            return true;
        }

        if (!isCurrentOrParentUrl(item.href)) {
            return false;
        }

        const href = toUrl(item.href);

        return !items.some((other) => {
            const otherHref = toUrl(other.href);

            return (
                otherHref !== href &&
                otherHref.startsWith(`${href}/`) &&
                isCurrentOrParentUrl(other.href)
            );
        });
    };

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Platform</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isActive(item)}
                            tooltip={{ children: item.title }}
                            closeMobileOnClick
                            className="data-[active=true]:bg-muted"
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
