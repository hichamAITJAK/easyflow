<?php

namespace App\Enums;

/** Lifecycle of a creative product: testing → active ⇄ inactive, and back to testing. */
enum CreativeProductStatus: string
{
    case TESTING = 'testing';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
