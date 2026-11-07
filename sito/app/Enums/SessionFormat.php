<?php

namespace App\Enums;

/** Formato di una sessione del programma (colonna format di sessions.csv). */
enum SessionFormat: string
{
    case Plenary = 'plenary';
    case Keynote = 'keynote';
    case Lecture = 'lecture';
    case Panel = 'panel';
    case Workshop = 'workshop';
    case Symposium = 'symposium';
    case Course = 'course';
    case Break = 'break';
    case Social = 'social';

    /** Etichetta in inglese, tradotta in lang/it.json. */
    public function label(): string
    {
        return __(ucfirst($this->value));
    }
}
