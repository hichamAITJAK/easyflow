import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, Headset } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { AppearanceDropdown } from '@/components/appearance-dropdown';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { LanguageDropdown } from '@/components/language-dropdown';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { UserMenuContent } from '@/components/user-menu-content';
import { getVisibleNavItems } from '@/config/nav-items';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import { useTranslation } from '@/hooks/use-translation';
import { cn, toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { BreadcrumbItem, PageProps } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

/*
  The confirmer shell per the approved preview: a top bar with the mark,
  a role chip and the account menu; the sections as underlined tabs on
  desktop and as a bottom tab bar on phones — agents work from their
  phones, so the sections stay one thumb away instead of behind a drawer.
*/
export function AppHeader({ breadcrumbs = [] }: Props) {
    const { t } = useTranslation();

    const page = usePage<PageProps>();
    const { auth } = page.props;
    const getInitials = useInitials();
    const { isCurrentUrl } = useCurrentUrl();
    const mainNavItems = getVisibleNavItems(auth.user?.role);
    const activeNavItem = mainNavItems.find((item) => isCurrentUrl(item.href));

    return (
        <>
            <div className="sticky top-0 z-40 border-b border-border bg-background">
                <div className="flex h-14 items-center gap-3 px-4 lg:px-6">
                    <Link
                        href={dashboard()}
                        prefetch
                        className="flex shrink-0 items-center rounded-lg"
                    >
                        <AppLogoIcon className="size-9 rounded-lg motion-safe:animate-logo-spin" />
                    </Link>

                    <div className="hidden sm:block">
                        <Breadcrumbs breadcrumbs={breadcrumbs} />
                    </div>

                    {auth.user?.role === 'confirmation_agent' && (
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-[#16A08E]/10 px-2.5 py-1 text-xs font-semibold text-[#0C7D6F]">
                            <Headset className="size-3.5" />
                            {t('Confirmation Agent')}
                        </span>
                    )}

                    <div className="ml-auto flex items-center gap-1">
                        <LanguageDropdown />
                        <AppearanceDropdown />
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="ml-1 h-auto gap-2 rounded-md p-1 pr-2"
                                >
                                    <Avatar className="size-8 overflow-hidden rounded-full">
                                        <AvatarImage
                                            src={auth.user?.avatar ?? undefined}
                                            alt={auth.user?.name}
                                        />
                                        <AvatarFallback className="rounded-full bg-secondary text-xs font-semibold text-secondary-foreground">
                                            {getInitials(auth.user?.name ?? '')}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="hidden text-left sm:block">
                                        <span className="block text-sm leading-tight font-semibold">
                                            {auth.user?.name}
                                        </span>
                                        <span className="block text-xs leading-tight text-muted-foreground">
                                            {t('Confirmer')}
                                        </span>
                                    </span>
                                    <ChevronDown className="size-4 text-muted-foreground" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent className="w-56" align="end">
                                {auth.user && (
                                    <UserMenuContent user={auth.user} />
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                {mainNavItems.length > 0 && (
                    <div className="hidden px-4 lg:block lg:px-6">
                        <Tabs
                            value={
                                activeNavItem
                                    ? toUrl(activeNavItem.href)
                                    : undefined
                            }
                            onValueChange={(value: string) =>
                                router.visit(value)
                            }
                        >
                            <TabsList variant="line" className="h-10 gap-1">
                                {mainNavItems.map((item) => (
                                    <TabsTrigger
                                        key={item.title}
                                        value={toUrl(item.href)}
                                        className="h-10 cursor-pointer gap-2 px-3 text-sm font-medium text-foreground/70 data-[state=active]:font-semibold data-[state=active]:text-primary"
                                    >
                                        {item.icon && (
                                            <item.icon className="size-4 shrink-0" />
                                        )}
                                        {t(item.title)}
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>
                    </div>
                )}
            </div>

            {/* Bottom tab bar on phones. Scrolls sideways when the sections
                outgrow the width; a fade on the right hints at the rest. */}
            {mainNavItems.length > 0 && (
                <div className="fixed inset-x-0 bottom-0 z-40 lg:hidden">
                    <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-10 bg-gradient-to-l from-background to-transparent" />
                    <nav
                        className="flex [scrollbar-width:none] overflow-x-auto border-t border-border bg-background pb-[env(safe-area-inset-bottom)]"
                        aria-label={t('Sections')}
                    >
                        {mainNavItems.map((item) => {
                            const active = activeNavItem?.title === item.title;

                            return (
                                <Link
                                    key={item.title}
                                    href={item.href}
                                    prefetch
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'flex min-w-[92px] flex-none flex-col items-center gap-1 px-1 pt-2.5 pb-2',
                                        active
                                            ? 'text-primary'
                                            : 'text-muted-foreground active:text-foreground',
                                    )}
                                >
                                    {item.icon && (
                                        <item.icon className="size-5" />
                                    )}
                                    <span
                                        className={cn(
                                            'text-[10px] leading-none',
                                            active
                                                ? 'font-semibold'
                                                : 'font-medium',
                                        )}
                                    >
                                        {t(item.title)}
                                    </span>
                                </Link>
                            );
                        })}
                    </nav>
                </div>
            )}
        </>
    );
}
