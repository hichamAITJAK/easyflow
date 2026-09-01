<?php

namespace App\Enums;

enum SubscriptionPaymentMethod: string
{
    case BANK_TRANSFER = 'bank_transfer';
    case CASH = 'cash';
}
