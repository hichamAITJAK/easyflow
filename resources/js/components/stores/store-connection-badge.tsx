import { ConnectionStatusBadge } from '@/components/connection-status-badge';
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
    if (status === 'connected') {
        return <ConnectionStatusBadge tone="live">Connected</ConnectionStatusBadge>;
    }

    if (status === 'failed') {
        return (
            <ConnectionStatusBadge tone="error">
                Connection failed
            </ConnectionStatusBadge>
        );
    }

    return <ConnectionStatusBadge tone="pending">Connecting…</ConnectionStatusBadge>;
}
