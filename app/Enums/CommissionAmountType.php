<?php

namespace App\Enums;

enum CommissionAmountType: string
{
    case FIXED = 'fixed';
    case PERCENTAGE = 'percentage';
}
