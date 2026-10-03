import AppLogoIcon from '@/components/app-logo-icon';
import AppWordmark from '@/components/app-wordmark';

/**
 * Sidebar header. The wordmark already carries the mark as its "o", so it
 * stands alone when there is room; when the sidebar collapses to icons,
 * only the mark fits and the wordmark steps aside for it.
 */
export default function AppLogo() {
    return (
        <>
            <AppWordmark className="h-6 group-data-[collapsible=icon]:hidden" />
            <AppLogoIcon className="hidden size-7 group-data-[collapsible=icon]:block" />
        </>
    );
}
