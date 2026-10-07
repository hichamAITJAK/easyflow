import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { useIsMobile } from '@/hooks/use-mobile';
import { useTranslation } from '@/hooks/use-translation';
import type { PageProps } from '@/types';

const ROLE_LABELS: Record<string, string> = {
    super_admin: 'Super admin',
    admin: 'Admin',
    confirmation_agent: 'Confirmation agent',
    fulfilment_agent: 'Fulfilment agent',
};

export function NavUser() {
    const { auth } = usePage<PageProps>().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const getInitials = useInitials();
    const { t } = useTranslation();

    if (!auth.user) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="group h-auto gap-2.5 rounded-md p-1.5 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0 hover:bg-accent data-[state=open]:bg-accent"
                            data-test="sidebar-menu-button"
                        >
                            <span className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-secondary text-xs font-semibold text-secondary-foreground">
                                {auth.user.avatar ? (
                                    <img
                                        src={auth.user.avatar}
                                        alt={auth.user.name}
                                        className="size-full object-cover"
                                    />
                                ) : (
                                    getInitials(auth.user.name)
                                )}
                            </span>
                            <span className="min-w-0 flex-1 text-left">
                                <span className="block truncate text-sm leading-tight font-semibold">
                                    {auth.user.name}
                                </span>
                                <span className="block truncate text-xs text-muted-foreground">
                                    {t(
                                        ROLE_LABELS[auth.user.role] ??
                                            auth.user.role,
                                    )}
                                </span>
                            </span>
                            <ChevronsUpDown className="ml-auto size-4 shrink-0 text-muted-foreground" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="end"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'left'
                                  : 'bottom'
                        }
                    >
                        <UserMenuContent user={auth.user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
