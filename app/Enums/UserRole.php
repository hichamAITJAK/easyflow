<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case CONFIRMATION_AGENT = 'confirmation_agent';
    case FULFILMENT_AGENT = 'fulfilment_agent';
}
