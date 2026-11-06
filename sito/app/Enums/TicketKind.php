<?php

namespace App\Enums;

enum TicketKind: string
{
    case FullPass = 'full_pass';
    case DayPass = 'day_pass';
    case PaidSession = 'paid_session';
    case Companion = 'companion';
    case Exhibitor = 'exhibitor';
    case StaffPass = 'staff_pass';
}
