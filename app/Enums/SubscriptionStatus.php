<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case TRIALING = 'trialing';

    /** Payment claimed by the business, waiting for super admin review. */
    case PENDING = 'pending';

    case ACTIVE = 'active';

    /** Payment request reviewed and refused by the super admin. */
    case REJECTED = 'rejected';

    case EXPIRED = 'expired';

    case CANCELLED = 'cancelled';
}
