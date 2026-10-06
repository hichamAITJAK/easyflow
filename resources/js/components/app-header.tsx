import { Link, router, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useState } from 'react';
import AppLogo from '@/components/app-logo';
import AppWordmark from '@/components/app-wordmark';
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
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { UserMenuContent } from '@/components/user-menu-content';
import { getVisibleNavItems } from '@/config/nav-items';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import { useTranslation } from '@/hooks/use-translation';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { BreadcrumbItem, PageProps } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

export function AppHeader({ breadcrumbs = [] }: Props) {
    const { t } = useTranslation();

    const page = usePage<PageProps>();
    const { auth } = page.props;
    const getInitials = useInitials();
    const { isCurrentUrl } = useCurrentUrl();
    const mainNavItems = getVisibleNavItems(auth.user?.role);
    const activeNavItem = mainNavItems.find((item) => isCurrentUrl(item.href));

    // Controlled so a tap on a nav link can close the sheet: an Inertia
    // visit swaps the page under it, and an uncontrolled sheet would stay
    // open over the new page.
    const [mobileNavOpen, setMobileNavOpen] = useState(false);

    return (
        <>
            <div>
                <div className="mx-auto flex h-16 items-center gap-2 px-4 md:max-w-[95rem]">
                    {/* Mobile Menu */}
                    <div className="lg:hidden">
                        <Sheet
                            open={mobileNavOpen}
                            onOpenChange={setMobileNavOpen}
                        >
                            <SheetTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="mr-2 h-[34px] w-[34px]"
                                >
                                    <Menu className="h-5 w-5" />
                                </Button>
                            </SheetTrigger>
                            <SheetContent
                                side="left"
                                className="flex h-full w-64 flex-col items-stretch justify-between bg-sidebar"
                            >
                                <SheetTitle className="sr-only">
                                    {t('Navigation menu')}
                                </SheetTitle>
                                <SheetHeader className="flex justify-start text-left">
                                    <AppWordmark className="h-6" />
                                </SheetHeader>
                                <div className="flex h-full flex-1 flex-col space-y-4 p-4">
                                    <div className="flex flex-col space-y-4 text-sm">
                                        {mainNavItems.map((item) => (
                                            <Link
                                                key={item.title}
                                                href={item.href}
                                                onClick={() =>
                                                    setMobileNavOpen(false)
                                                }
                                                className="flex items-center space-x-2 font-medium"
                                            >
                                                {item.icon && (
                                                    <item.icon className="h-5 w-5" />
                                                )}
                                                <span>{t(item.title)}</span>
                                            </Link>
                                        ))}
                                    </div>
                                </div>
                            </SheetContent>
                        </Sheet>
                    </div>

                    {/* Hidden on mobile: the tile and wordmark sit between the
                        menu button and the breadcrumbs, crowding the row until
                        the app name truncates to "Ea…" and the page name wraps.
                        The nav sheet still carries the mark, and the header is
                        reachable only when signed in — branding here is
                        decoration, the breadcrumb is wayfinding. */}
                    <Link
                        href={dashboard()}
                        prefetch
                        className="hidden items-center space-x-2 lg:flex"
                    >
                        <AppLogo />
                    </Link>

                    <Breadcrumbs breadcrumbs={breadcrumbs} />

                    <div className="ml-auto flex items-center">
                        <LanguageDropdown />
                        <AppearanceDropdown />
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="size-10 rounded-full p-1"
                                >
                                    <Avatar className="size-8 overflow-hidden rounded-full">
                                        <AvatarImage
                                            src={auth.user?.avatar ?? undefined}
                                            alt={auth.user?.name}
                                        />
                                        <AvatarFallback className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                            {getInitials(auth.user?.name ?? '')}
                                        </AvatarFallback>
                                    </Avatar>
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
            </div>
            {mainNavItems.length > 0 && (
                <div className="hidden border-b border-sidebar-border/70 lg:block">
                    <div className="mx-auto px-4 md:max-w-[95rem]">
                        <Tabs
                            value={
                                activeNavItem
                                    ? toUrl(activeNavItem.href)
                                    : undefined
                            }
                            onValueChange={(value) => router.visit(value)}
                        >
                            <TabsList variant="line" className="h-11">
                                {mainNavItems.map((item) => (
                                    <TabsTrigger
                                        key={item.title}
                                        value={toUrl(item.href)}
                                        className="cursor-pointer px-4 py-2.5 text-[15px]"
                                    >
                                        {item.icon && (
                                            <item.icon className="mr-2 h-4.5 w-4.5" />
                                        )}
                                        {t(item.title)}
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>
                    </div>
                </div>
            )}
        </>
    );
}
