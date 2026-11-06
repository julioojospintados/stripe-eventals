<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Valid = 'valid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
}
