import { router } from '@inertiajs/react';

/**
 * Routes every PATCH/PUT/DELETE over POST with Laravel's `_method` field.
 *
 * The production host (o2switch) resets PATCH connections at the WAF layer
 * before they ever reach PHP — on every URL, static files included, over
 * both HTTP/1.1 and HTTP/2 — so the browser only ever sees a bare network
 * error with no response to read. POST is not blocked, and Laravel resolves
 * `_method` before routing, so `Route::patch(...)` keeps matching untouched.
 *
 * This is a transport-level workaround for a host restriction, not an
 * application concern: it belongs in one place rather than at each of the
 * ~30 call sites, and it can be deleted wholesale if the host ever
 * unblocks PATCH.
 */

const SPOOFED = ['patch', 'put', 'delete'] as const;

type SpoofedMethod = (typeof SPOOFED)[number];

export function installMethodSpoofing(): void {
    const original = router.visit.bind(router);

    router.visit = (href, options = {}) => {
        const method = options.method?.toLowerCase() as
            | SpoofedMethod
            | undefined;

        if (!method || !SPOOFED.includes(method)) {
            return original(href, options);
        }

        const data = options.data;

        // FormData is mutated in place: rebuilding it would drop the File
        // entries that are the whole reason a caller reached for it.
        if (data instanceof FormData) {
            data.append('_method', method);

            return original(href, { ...options, method: 'post', data });
        }

        // Cast: visit() is generic over the caller's data shape, which by
        // construction cannot know about the added `_method` key.
        return original(href, {
            ...options,
            method: 'post',
            data: { ...(data ?? {}), _method: method },
        } as Parameters<typeof original>[1]);
    };
}
