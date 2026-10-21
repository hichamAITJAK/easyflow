import { request } from '@/lib/fulfillment-api';
import type {
    DeliveryAction,
    DeliveryActivityEvent,
    DeliveryCash,
    DeliveryGuyParcel,
    DeliveryParcel,
    DeliveryScanResult,
    DeliverySummary,
} from '@/types/delivery-guy';

/**
 * The delivery guy's workspace client. Same XHR shape as the warehouse
 * one (and the same error wrapping): a scan happens every few seconds on
 * the road, with patchy data, and a page reload per parcel would kill the
 * camera stream between scans.
 */
export const deliveryApi = {
    summary: () => request<DeliverySummary>('/delivery/summary'),

    /** Read-only preview. Never mutates. */
    scan: (qrValue: string) =>
        request<DeliveryScanResult>('/delivery/scan', { qr_value: qrValue }),

    apply: (body: {
        order_id: number;
        action: string;
        postponed_until?: string;
        note?: string;
    }) =>
        request<{
            order: DeliveryParcel;
            actions: DeliveryAction[];
            summary: DeliverySummary;
        }>('/delivery/apply', body),

    activity: () =>
        request<{ events: DeliveryActivityEvent[] }>('/delivery/activity'),

    undo: (eventId: number) =>
        request<{ order: DeliveryParcel; summary: DeliverySummary }>(
            '/delivery/undo',
            { event_id: eventId },
        ),

    cash: () => request<DeliveryCash>('/delivery/cash'),

    parcels: () =>
        request<{ parcels: DeliveryGuyParcel[] }>('/delivery/parcels'),
};
