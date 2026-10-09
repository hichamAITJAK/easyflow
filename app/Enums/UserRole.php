<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case CONFIRMATION_AGENT = 'confirmation_agent';
    case FULFILMENT_AGENT = 'fulfilment_agent';

    /** Produces ad creatives for the Creatives module; no orders, no warehouse. */
    case CREATIVES_EDITOR = 'creatives_editor';
}
