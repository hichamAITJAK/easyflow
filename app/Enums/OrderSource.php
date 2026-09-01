<?php

namespace App\Enums;

/**
 * Valid source_platform values for a manually-created order — how it
 * reached the business, since a manual order has no ecom platform to
 * record there instead. Platform-synced orders use their store's platform
 * slug in the same column; these values never appear on a synced order.
 */
enum OrderSource: string
{
    case WHATSAPP = 'whatsapp';
    case PHONE_CALL = 'phone_call';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WHATSAPP => 'WhatsApp',
            self::PHONE_CALL => 'Phone call',
            self::OTHER => 'Other',
        };
    }
}
