<?php

namespace App\Enums;

enum CompReason: string
{
    case Speaker = 'speaker';
    case Staff = 'staff';
    case Sponsor = 'sponsor';
    case Other = 'other';
}
