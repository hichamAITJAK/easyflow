<?php

namespace App\Enums;

enum PerformanceMetric: string
{
    case CONFIRMATION_RATE = 'confirmation_rate';
    case DELIVERY_SUCCESS_RATE = 'delivery_success_rate';
}
