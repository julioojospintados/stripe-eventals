<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateCsvExport;
use App\Services\CsvExports;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Export CSV. Fino a CsvExports::$syncLimit righe si scarica subito; oltre il file si prepara in coda e compare qui
 * sotto, privato (solo chi l'ha chiesto), per 7 giorni. Ogni export va nel registro audit.
 */
class Exports extends Page
{
    protected string $view = 'filament.pages.exports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Exports';

    protected static ?string $navigationLabel = 'CSV exports';

    protected static ?string $title = 'CSV exports';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return collect(CsvExports::PERMISSIONS)->contains(fn ($permission) => $user?->can($permission));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->exportAction('tickets', 'Check-in partner: valid tickets'),
            $this->exportAction('orders', 'Orders (accounting)'),
            $this->exportAction('consents', 'Newsletter consents'),
        ];
    }

    private function exportAction(string $type, string $label): Action
    {
        return Action::make('export_'.$type)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn () => auth()->user()->can(CsvExports::PERMISSIONS[$type]))
            ->action(function (CsvExports $exports) use ($type) {
                $user = auth()->user();
                abort_unless($user->can(CsvExports::PERMISSIONS[$type]), 403);
                $rows = $exports->count($type);
                $filename = $exports->filename($type);

                if ($rows > CsvExports::$syncLimit) {
                    GenerateCsvExport::dispatch($type, $user->id, $filename);
                    $exports->audit($type, $user, $rows, 'queued');
                    Notification::make()->success()->title('Export queued')
                        ->body("$rows rows: the file appears on this page within a few minutes.")->send();

                    return null;
                }

                $exports->audit($type, $user, $rows, 'download');

                return response()->streamDownload(fn () => $exports->write($type, fopen('php://output', 'w')), $filename, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            });
    }

    /** File pronti di questo utente, i più recenti prima. */
    public function getFiles(): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(CsvExports::path(auth()->user())))
            ->map(fn ($path) => ['name' => basename($path), 'time' => $disk->lastModified($path), 'size' => $disk->size($path)])
            ->sortByDesc('time')->values()->all();
    }
}
