<?php

namespace App\Enums;

enum EcomPlatform: string
{
    case SHOPIFY = 'Shopify';
    case YOUCAN = 'YouCan';
    case WOOCOMMERCE = 'WooCommerce';
    case LIGHTFUNNELS = 'LightFunnels';
    case STOREEP = 'Storeep';
}
