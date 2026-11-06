<?php

namespace App\Enums;

enum CapacityHoldStatus: string
{
    case Active = 'active';
    case AwaitingPayment = 'awaiting_payment';
    case Converted = 'converted';
    case Released = 'released';
}
