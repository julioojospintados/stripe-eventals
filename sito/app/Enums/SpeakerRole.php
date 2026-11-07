<?php

namespace App\Enums;

/** Ruolo di uno speaker in una sessione. */
enum SpeakerRole: string
{
    case Speaker = 'speaker';
    case Moderator = 'moderator';

    public function label(): string
    {
        return __(ucfirst($this->value));
    }
}
