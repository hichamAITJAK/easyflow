import type {
    FulfillmentActivityEvent,
    FulfillmentOrder,
    FulfillmentScanResult,
    FulfillmentSummary,
} from '@/types/fulfillment';

/**
 * Thin XHR client for the fulfilment workspace.
 *
 * These endpoints are called as fetch rather than Inertia visits on
 * purpose: a scan/confirm round trip happens every few seconds, and an
 * Inertia visit would remount the page and tear down the live camera
 * stream between every parcel.
 *
 * Only GET and POST are used here. PATCH/PUT/DELETE are reset by the
 * production host's WAF before reaching PHP (see lib/method-spoofing.ts),
 * so the routes backing this file are all declared as POST.
 */

/** Laravel's 422 validation shape. */
type ValidationErrorBody = {
    message?: string;
    errors?: Record<string, string[]>;
};

export class FulfillmentApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
    ) {
        super(message);
        this.name = 'FulfillmentApiError';
    }
}

/**
 * Laravel's CSRF token, read from the XSRF-TOKEN cookie it sets on every
 * session response. The blade layout renders no <meta name="csrf-token">,
 * so the cookie is the only source available — this is the same route
 * Inertia's own requests take. The value is URL-encoded in the cookie and
 * must be decoded before being sent back in the header.
 */
function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T>(url: string, body?: Record<string, unknown>): Promise<T> {
    let response: Response;

    try {
        response = await fetch(url, {
            method: body ? 'POST' : 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(body
                    ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() }
                    : {}),
            },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined,
        });
    } catch {
        // Warehouses have patchy wifi, and the raw fetch rejection reads as
        // "Failed to fetch", which tells an agent nothing actionable.
        throw new FulfillmentApiError(
            'No connection. Check the network and scan again.',
            0,
        );
    }

    if (response.ok) {
        return (await response.json()) as T;
    }

    // A 419 means the session expired while the tab sat open overnight —
    // common on a shared warehouse phone, and only a reload fixes it.
    if (response.status === 419) {
        throw new FulfillmentApiError(
            'Your session expired. Reload the page and sign in again.',
            419,
        );
    }

    let message = 'Something went wrong. Try scanning again.';

    try {
        const parsed = (await response.json()) as ValidationErrorBody;
        const firstError = parsed.errors ? Object.values(parsed.errors)[0]?.[0] : undefined;
        message = firstError ?? parsed.message ?? message;
    } catch {
        // Non-JSON error body (an HTML error page from the host, say) —
        // the generic message above stands.
    }

    throw new FulfillmentApiError(message, response.status);
}

export const fulfillmentApi = {
    summary: () => request<FulfillmentSummary>('/fulfillment/summary'),

    /** Read-only preview. Never mutates — see FulfillmentController::scan(). */
    scan: (qrValue: string) =>
        request<FulfillmentScanResult>('/fulfillment/scan', { qr_value: qrValue }),

    /** Commits the action the server re-derives from the parcel's current status. */
    confirm: (orderId: number) =>
        request<{ order: FulfillmentOrder }>('/fulfillment/confirm', { order_id: orderId }),

    activity: () =>
        request<{ events: FulfillmentActivityEvent[] }>('/fulfillment/activity'),

    undo: (eventId: number) =>
        request<{ order: FulfillmentOrder }>('/fulfillment/undo', { event_id: eventId }),
};
