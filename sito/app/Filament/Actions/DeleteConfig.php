<?php

namespace App\Filament\Actions;

use App\Services\ConfigDeletion;
use App\Services\DeletionBlocked;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Elimina (singola e di massa) per i dati di configurazione: permesso <risorsa>.delete dalla Policy, conferma, poi
 * ConfigDeletion, che rifiuta con un messaggio leggibile se il dato è usato e scrive l'audit.
 */
final class DeleteConfig
{
    private const WARNING = 'Si elimina solo se nessun ordine, biglietto o riserva lo usa. Non si può annullare.';

    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(self::WARNING)
            ->using(function (Model $record): bool {
                try {
                    app(ConfigDeletion::class)->delete($record, auth()->user());

                    return true;
                } catch (DeletionBlocked $e) {
                    Notification::make()->danger()->title('Non eliminato')->body($e->getMessage())->persistent()->send();

                    return false;
                }
            });
    }

    public static function bulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->modalDescription(self::WARNING)
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                foreach ($records as $record) {
                    try {
                        app(ConfigDeletion::class)->delete($record, auth()->user());
                    } catch (DeletionBlocked $e) {
                        $action->reportBulkProcessingFailure(md5($e->getMessage()), $e->getMessage());
                    }
                }
            });
    }
}
