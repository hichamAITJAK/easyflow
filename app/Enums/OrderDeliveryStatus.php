<?php

namespace App\Enums;

enum OrderDeliveryStatus: string
{
    case AWAITING_PICKUP = 'awaiting_pickup';
    case READY_FOR_PICKUP = 'ready_for_pickup';
    case IN_TRANSIT = 'in_transit';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case POSTPONED = 'postponed';
    case CHANGED = 'changed';
    case DELIVERY_ATTEMPT_FAILED = 'delivery_attempt_failed';
    case REFUSED = 'refused';
    case DELIVERED = 'delivered';
    case RETURNED_IN_TRANSIT = 'returned_in_transit';
    case RETURN_RECEIVED = 'return_received';
    case CANCELLED_AT_COURIER = 'cancelled_at_courier';

    /**
     * Get the human-readable description for the status.
     */
    public function description(): string
    {
        return match ($this) {
            self::AWAITING_PICKUP => 'Parcel registered at courier, not yet collected',
            self::READY_FOR_PICKUP => 'Fulfillment agent has scanned and physically staged the parcel; courier has not yet collected it',
            self::IN_TRANSIT => 'Courier has collected the parcel and it\'s on the way',
            self::OUT_FOR_DELIVERY => 'Courier has assigned a delivery driver to the parcel for final-mile delivery',
            self::POSTPONED => 'Delivery rescheduled',
            self::CHANGED => 'Client changed something after shipment (address, product)',
            self::DELIVERY_ATTEMPT_FAILED => 'Courier\'s driver couldn\'t reach the client/address at the point of delivery',
            self::REFUSED => 'Client refused the parcel at the door',
            self::DELIVERED => 'Client received and paid',
            self::RETURNED_IN_TRANSIT => 'Courier reports the parcel as being sent back; physically still with the courier',
            self::RETURN_RECEIVED => 'Fulfillment agent has scanned the returned parcel back into the warehouse; it is physically here, not with the courier',
            self::CANCELLED_AT_COURIER => 'Parcel cancelled on the courier\'s side',
        };
    }
}
