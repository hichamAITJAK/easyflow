<?php

namespace App\Enums;

enum StoreConnectionStatus: string
{
    case PENDING = 'pending';
    case CONNECTED = 'connected';
    case FAILED = 'failed';
}
