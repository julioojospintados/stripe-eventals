<?php

namespace App\Enums;

enum ProgramSessionAccess: string
{
    case Included = 'included';
    case IncludedBooking = 'included_booking';
    case Paid = 'paid';
}
