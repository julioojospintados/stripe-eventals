<?php

namespace App\Filament\Pages;

use App\Jobs\GenerateCsvExport;
use App\Models\Event;
use App\Services\CsvExports;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
            // Biglietti e ordini di un evento alla volta: il partner del check-in e la contabilità lavorano per evento.
            ->schema($type === 'consents' ? [] : [
                Select::make('event_id')->label('Event')->options(fn () => Event::latest('starts_on')->pluck('slug', 'id'))
                    ->default(fn () => Event::defaultId())->required(),
            ])
            ->action(function (CsvExports $exports, array $data) use ($type) {
                $user = auth()->user();
                abort_unless($user->can(CsvExports::PERMISSIONS[$type]), 403);
                $eventId = $data['event_id'] ?? null;
                $rows = $exports->count($type, $eventId);
                $filename = $exports->filename($type);

                if ($rows > CsvExports::$syncLimit) {
                    GenerateCsvExport::dispatch($type, $user->id, $filename, $eventId);
                    $exports->audit($type, $user, $rows, 'queued', $eventId);
                    Notification::make()->success()->title('Export queued')
                        ->body("$rows rows: the file appears on this page within a few minutes.")->send();

                    return null;
                }

                $exports->audit($type, $user, $rows, 'download', $eventId);

                return response()->streamDownload(fn () => $exports->write($type, fopen('php://output', 'w'), $eventId), $filename, [
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
