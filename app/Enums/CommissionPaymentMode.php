<?php

namespace App\Enums;

enum CommissionPaymentMode: string
{
    case SALARY = 'salary';
    case COMMISSION = 'commission';
}
