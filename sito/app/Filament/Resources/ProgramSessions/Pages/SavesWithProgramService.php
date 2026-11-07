<?php

namespace App\Filament\Resources\ProgramSessions\Pages;

use App\Models\ProgramSession;
use App\Services\ProgramException;
use App\Services\ProgramService;
use Filament\Notifications\Notification;

/** Creazione e modifica passano da ProgramService: una regola violata diventa un avviso leggibile, non un errore 500. */
trait SavesWithProgramService
{
    protected function saveSession(ProgramSession $session): ProgramSession
    {
        try {
            return app(ProgramService::class)->save($session);
        } catch (ProgramException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();
            $this->halt();

            throw $e;   // non ci arriva: halt() interrompe sempre il salvataggio
        }
    }
}
