<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case CONFIRMATION_AGENT = 'confirmation_agent';
    case FULFILMENT_AGENT = 'fulfilment_agent';

    /** Delivers the business's own parcels in one city, instead of a courier company. */
    case DELIVERY_GUY = 'delivery_guy';

    /** Produces ad creatives for the Creatives module; no orders, no warehouse. */
    case CREATIVES_EDITOR = 'creatives_editor';
}
