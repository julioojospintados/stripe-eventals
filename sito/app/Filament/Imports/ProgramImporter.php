<?php

namespace App\Filament\Imports;

use App\Models\Event;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;
use Illuminate\Support\Number;

/**
 * Base degli import CSV del programma, nel formato di contenuti/modelli/README.md: il codice è la chiave (reimportare
 * aggiorna), le righe EXAMPLE- si saltano, ogni riga sbagliata finisce nel file degli errori con il motivo.
 * Gli Importer non passano dalle Policy: l'azione di import è visibile solo con il permesso program.import.
 */
abstract class ProgramImporter extends Importer
{
    private ?Event $event = null;

    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('event_id')->label('Event')->required()
                ->options(fn () => Event::latest('starts_on')->pluck('slug', 'id'))
                ->default(fn () => Event::defaultId()),
        ];
    }

    protected function event(): Event
    {
        return $this->event ??= Event::findOrFail($this->options['event_id'] ?? Event::defaultId());
    }

    protected static function isExample(mixed $code): bool
    {
        return str_starts_with(strtoupper((string) $code), 'EXAMPLE-');
    }

    /** Colonne <campo>_en e <campo>_it in un testo tradotto; le celle vuote non si salvano (il sito ripiega sull'inglese). */
    protected function texts(string $field): ?array
    {
        $texts = array_filter([
            'en' => $this->data["{$field}_en"] ?? null,
            'it' => $this->data["{$field}_it"] ?? null,
        ], fn ($text) => filled($text));

        return $texts ?: null;
    }

    protected function fail(string $message): never
    {
        throw new RowImportFailedException($message);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Import finished: '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported or skipped as examples.';
        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' with errors: download them, fix them and import them again.';
        }

        return $body;
    }
}
