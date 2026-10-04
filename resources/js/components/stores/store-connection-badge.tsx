import { ConnectionStatusBadge } from '@/components/connection-status-badge';
import { useTranslation } from '@/hooks/use-translation';
import type { StoreConnectionStatus } from '@/types';

/**
 * Maps a store's own status vocabulary onto the shared integration badge.
 * Failed leads with the problem, not blame; pending stays honestly neutral.
 */
export function StoreConnectionBadge({
    status,
}: {
    status: StoreConnectionStatus;
}) {
    const { t } = useTranslation();

    if (status === 'connected') {
        return (
            <ConnectionStatusBadge tone="live">
                {t('Connected')}
            </ConnectionStatusBadge>
        );
    }

    if (status === 'failed') {
        return (
            <ConnectionStatusBadge tone="error">
                {t('Connection failed')}
            </ConnectionStatusBadge>
        );
    }

    return (
        <ConnectionStatusBadge tone="pending">
            {t('Connecting…')}
        </ConnectionStatusBadge>
    );
}
