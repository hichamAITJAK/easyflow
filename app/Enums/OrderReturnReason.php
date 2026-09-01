<?php

namespace App\Enums;

enum OrderReturnReason: string
{
    case CLIENT_REFUSED = 'client_refused';
    case CLIENT_UNREACHABLE_AT_DELIVERY = 'client_unreachable_at_delivery';
    case WRONG_ADDRESS = 'wrong_address';
    case PAYMENT_ISSUE = 'payment_issue';
    case DAMAGED_IN_TRANSIT = 'damaged_in_transit';
    case OTHER = 'other';

    /**
     * Get the human-readable description for the return reason.
     */
    public function description(): string
    {
        return match ($this) {
            self::CLIENT_REFUSED => 'Client refused to accept the parcel',
            self::CLIENT_UNREACHABLE_AT_DELIVERY => 'Same as the delivery_attempt_failed case, but exhausted retries',
            self::WRONG_ADDRESS => "Courier couldn't locate the address",
            self::PAYMENT_ISSUE => "Client couldn't pay the COD amount",
            self::DAMAGED_IN_TRANSIT => 'Product arrived damaged, client refused',
            self::OTHER => 'Free text',
        };
    }
}
