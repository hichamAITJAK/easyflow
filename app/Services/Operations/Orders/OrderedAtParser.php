<?php

namespace App\Services\Operations\Orders;

use App\Enums\EcomPlatform;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Interprets a platform order's raw creation timestamp into a Carbon
 * instance. Each ecom platform's API represents "when the order was placed"
 * differently:
 *
 *  - Shopify's GraphQL `Order.createdAt` is an ISO 8601 string.
 *  - YouCan's REST `created_at` is a Unix epoch integer (arrives as a PHP
 *    int from json_decode, or a numeric string once it's passed through a
 *    loosely-typed DTO property).
 *  - Lightfunnels' GraphQL `Order.created_at` is a `TimeStamp` scalar; our
 *    queries request it pre-formatted as ISO 8601 (see
 *    LightfunnelsOrderService), so it arrives as a string here too.
 *
 * Centralizing this avoids each DTO/service guessing the format itself, and
 * keeps OrderSyncService free of platform-specific parsing.
 */
class OrderedAtParser
{
    public static function parse(?string $platformSlug, mixed $raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            if ($platformSlug === EcomPlatform::YOUCAN->value && is_numeric($raw)) {
                return Carbon::createFromTimestamp((int) $raw);
            }

            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
