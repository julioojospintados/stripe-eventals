<?php

namespace App\Services;

use RuntimeException;

/** Regola del programma non rispettata (sala occupata, giorno fuori dall'evento...). Il messaggio è per lo staff. */
class ProgramException extends RuntimeException {}
