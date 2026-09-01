<?php

namespace App\Enums;

enum DeliveryAccountStatus: string
{
    case ACTIVE = 'active';
    case UNVERIFIED = 'unverified';
}
