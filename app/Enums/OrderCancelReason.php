<?php

namespace App\Enums;

enum OrderCancelReason: string
{
    case CLIENT_UNREACHABLE = 'client_unreachable';
    case CLIENT_CHANGED_MIND = 'client_changed_mind';
    case PRICE_TOO_HIGH = 'price_too_high';
    case FOUND_CHEAPER = 'found_cheaper';
    case DUPLICATE_ORDER = 'duplicate_order';
    case BLACKLISTED_CLIENT = 'blacklisted_client';
    case INVALID_ADDRESS = 'invalid_address';
    case INVALID_PHONE = 'invalid_phone';
    case OUT_OF_STOCK = 'out_of_stock';
    case FRAUD_SUSPECTED = 'fraud_suspected';
    case AGENT_ERROR = 'agent_error';
    case OTHER = 'other';

    /**
     * Get the human-readable description for the reason.
     */
    public function description(): string
    {
        return match ($this) {
            self::CLIENT_UNREACHABLE => 'No answer after max contact attempts',
            self::CLIENT_CHANGED_MIND => 'Client no longer wants the product',
            self::PRICE_TOO_HIGH => 'Client refused due to price',
            self::FOUND_CHEAPER => 'Client found the same product elsewhere for less',
            self::DUPLICATE_ORDER => 'Same client/product already has an active order',
            self::BLACKLISTED_CLIENT => 'Client flagged as risky, order rejected preemptively',
            self::INVALID_ADDRESS => 'Address incomplete or outside delivery coverage',
            self::INVALID_PHONE => 'Phone number wrong or disconnected',
            self::OUT_OF_STOCK => "Seller can't fulfill the order",
            self::FRAUD_SUSPECTED => 'Agent suspects a fake or malicious order',
            self::AGENT_ERROR => 'Order was created/assigned by mistake',
            self::OTHER => 'Anything else — requires a free-text note',
        };
    }
}
