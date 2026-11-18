<?php

namespace App\Services;

use RuntimeException;

/** Il dato di configurazione è usato: il messaggio, in italiano, va mostrato così com'è nel pannello. */
class DeletionBlocked extends RuntimeException {}
