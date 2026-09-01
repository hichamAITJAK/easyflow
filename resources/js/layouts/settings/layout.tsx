import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import AppLayout from '@/layouts/app-layout';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editBusiness } from '@/routes/business';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as sessionsIndex } from '@/routes/sessions';
import { edit as editSubscription } from '@/routes/subscription';
import type { NavItem, PageProps } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Sessions',
        href: sessionsIndex(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
];

// Tenant-only: a super admin has no business, hence no subscription. The
// route is gated by `can:access-tenant-app`, so showing it to a super admin
// would be a link into a 403.
const subscriptionNavItem: NavItem = {
    title: 'Subscription',
    href: editSubscription(),
    icon: null,
};

// Admin-only: business-wide settings are gated by `can:manage-users`, so
// showing this to an agent would be a link into a 403.
const businessNavItem: NavItem = {
    title: 'Business',
    href: editBusiness(),
    icon: null,
};

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { auth } = usePage<PageProps>().props;

    const isSuperAdmin = auth.user?.role === 'super_admin';

    // Settings is shared by both surfaces, so the shell follows the user
    // rather than the route: a super admin keeps the platform sidebar
    // instead of being dropped into a tenant workspace they don't belong to.
    const Shell = isSuperAdmin ? SuperAdminLayout : AppLayout;

    const isAdmin =
        auth.user?.role === 'admin' || auth.user?.role === 'super_admin';

    const navItems = isSuperAdmin
        ? sidebarNavItems
        : [
              ...sidebarNavItems,
              ...(isAdmin ? [businessNavItem] : []),
              subscriptionNavItem,
          ];

    return (
        <Shell>
            {/* SuperAdminLayout already pads its <main>; AppLayout does not. */}
            <div className={cn(!isSuperAdmin && 'px-4 py-6')}>
                <Heading
                    title="Settings"
                    description="Manage your profile and account settings"
                />

                <div className="flex flex-col lg:flex-row lg:space-x-12">
                    <aside className="w-full max-w-xl lg:w-48">
                        <nav
                            className="flex flex-col space-y-1 space-x-0"
                            aria-label="Settings"
                        >
                            {navItems.map((item, index) => (
                                <Button
                                    key={`${toUrl(item.href)}-${index}`}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn('w-full justify-start', {
                                        'bg-muted': isCurrentOrParentUrl(
                                            item.href,
                                        ),
                                    })}
                                >
                                    <Link href={item.href}>
                                        {item.icon && (
                                            <item.icon className="h-4 w-4" />
                                        )}
                                        {item.title}
                                    </Link>
                                </Button>
                            ))}
                        </nav>
                    </aside>

                    <Separator className="my-6 lg:hidden" />

                    <div className="flex-1">
                        <section className="max-w-5xl space-y-12 mx-auto">
                            {children}
                        </section>
                    </div>
                </div>
            </div>
        </Shell>
    );
}
