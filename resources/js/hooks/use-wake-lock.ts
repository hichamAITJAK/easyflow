import { useEffect, useRef } from 'react';

/**
 * Holds the screen awake for as long as the calling component is mounted.
 *
 * A packing run is minutes of reading the screen between touches, so the
 * phone's own idle timer would lock it between parcels and force an
 * unlock before every scan.
 *
 * The lock is released by the browser whenever the tab is backgrounded
 * (and is not restored automatically), so it is re-acquired on
 * visibilitychange — otherwise the screen starts sleeping again after the
 * agent takes a call mid-shift.
 *
 * Unsupported on iOS below 16.4 and on Firefox Android; those browsers
 * simply keep their normal idle behaviour rather than breaking.
 */
export function useWakeLock() {
    const sentinel = useRef<WakeLockSentinel | null>(null);

    useEffect(() => {
        if (!('wakeLock' in navigator)) {
            return;
        }

        let cancelled = false;

        const acquire = async () => {
            if (document.visibilityState !== 'visible' || sentinel.current) {
                return;
            }

            try {
                const lock = await navigator.wakeLock.request('screen');

                if (cancelled) {
                    void lock.release();

                    return;
                }

                sentinel.current = lock;
                // Clear our handle when the browser drops the lock on its
                // own, so the next visibility change re-acquires instead of
                // assuming a dead lock is still held.
                lock.addEventListener('release', () => {
                    sentinel.current = null;
                });
            } catch {
                // Denied (low battery, or a policy that forbids it). The
                // screen just behaves normally — nothing to tell the agent.
            }
        };

        void acquire();
        document.addEventListener('visibilitychange', acquire);

        return () => {
            cancelled = true;
            document.removeEventListener('visibilitychange', acquire);
            void sentinel.current?.release();
            sentinel.current = null;
        };
    }, []);
}
