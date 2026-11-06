<?php

namespace App\Enums;

enum OrderOrigin: string
{
    case Checkout = 'checkout';
    case Complimentary = 'complimentary';
}
