<?php

namespace App\Enums;

enum TicketChannel: string
{
    case Public = 'public';
    case Invite = 'invite';
    case Panel = 'panel';
}
