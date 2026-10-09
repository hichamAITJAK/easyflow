import { usePage } from '@inertiajs/react';
import { Clapperboard } from 'lucide-react';
import { AppearanceDropdown } from '@/components/appearance-dropdown';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { LanguageDropdown } from '@/components/language-dropdown';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useTranslation } from '@/hooks/use-translation';
import type { BreadcrumbItem as BreadcrumbItemType, PageProps } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;

    return (
        <header className="flex h-12 shrink-0 items-center gap-2 border-b border-border bg-background px-4">
            <SidebarTrigger className="size-7" />
            <Breadcrumbs breadcrumbs={breadcrumbs} />

            {/* A creatives editor works a single module; the chip says so
                where the admin would see the full breadcrumb trail. */}
            {auth.user?.role === 'creatives_editor' && (
                <span className="ml-2 inline-flex items-center gap-1.5 rounded-full bg-[#468FA5]/12 px-2.5 py-1 text-xs font-semibold text-[#2E7389]">
                    <Clapperboard className="size-3.5" />
                    {t('Creatives Editor')}
                </span>
            )}

            <div className="ml-auto flex items-center gap-1">
                <LanguageDropdown />
                <AppearanceDropdown />
            </div>
        </header>
    );
}
