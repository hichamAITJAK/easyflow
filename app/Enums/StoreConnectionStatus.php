<?php

namespace App\Enums;

enum StoreConnectionStatus: string
{
    case PENDING = 'pending';
    case CONNECTED = 'connected';
    case FAILED = 'failed';

    /** A store with no online shop (see the Manual platform): never synced. */
    case MANUAL = 'manual';
}
