import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Tracks whether an Inertia visit is in flight, via the global start/finish
 * events — covers both `router.get` calls and `<Link>` navigations, so any
 * component using it reflects server-driven requests it didn't initiate
 * itself (e.g. a DataTable reacting to a sibling toolbar's filter change).
 */
export function useInertiaNavigating() {
    const [navigating, setNavigating] = useState(false);

    useEffect(() => {
        const removeStart = router.on('start', () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    return navigating;
}
