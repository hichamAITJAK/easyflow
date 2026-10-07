import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslation } from '@/hooks/use-translation';
import { toUrl } from '@/lib/utils';
import type { NavGroup, NavItem } from '@/types';

/** Section order in the sidebar. */
const GROUPS: NavGroup[] = ['Platform', 'Team', 'Finance', 'Setup'];

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const { t } = useTranslation();

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

    const sections = GROUPS.map((group) => ({
        group,
        items: items.filter((item) => (item.group ?? 'Platform') === group),
    })).filter((section) => section.items.length > 0);

    return (
        <>
            {sections.map((section, index) => (
                <SidebarGroup
                    key={section.group}
                    className={index === 0 ? 'px-2.5 pt-2 pb-0' : 'px-2.5 py-0'}
                >
                    <SidebarGroupLabel
                        className={
                            index === 0
                                ? 'h-auto px-2 pt-1 pb-1.5 text-xs font-medium text-muted-foreground'
                                : 'h-auto px-2 pt-5 pb-1.5 text-xs font-medium text-muted-foreground'
                        }
                    >
                        {t(section.group)}
                    </SidebarGroupLabel>
                    <SidebarMenu className="gap-0.5">
                        {section.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isActive(item)}
                                    tooltip={{ children: t(item.title) }}
                                    closeMobileOnClick
                                    className="h-9 gap-2.5 rounded-md px-2.5 font-medium text-foreground/75 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 hover:bg-accent hover:text-accent-foreground data-[active=true]:bg-primary data-[active=true]:font-medium data-[active=true]:text-primary-foreground data-[active=true]:hover:bg-primary data-[active=true]:hover:text-primary-foreground"
                                >
                                    <Link href={item.href} prefetch>
                                        {item.icon && (
                                            <item.icon className="size-4 shrink-0" />
                                        )}
                                        <span className="truncate">
                                            {t(item.title)}
                                        </span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
