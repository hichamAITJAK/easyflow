<?php

namespace App\Enums;

enum Courier: string
{
    case OZONEXPRESS = 'OzonExpress';
    case COLIIX = 'Coliix';
    case AMEEX = 'Ameex';
    case SENDIT = 'Sendit';
    case FORCELOG = 'FORCELOG';
    case CATHEDIS = 'Cathedis';
    case SPEEDAF = 'Speedaf Express';
}
