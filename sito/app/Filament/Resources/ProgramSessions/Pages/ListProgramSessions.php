<?php

namespace App\Filament\Resources\ProgramSessions\Pages;

use App\Filament\Imports\ProgramSessionImporter;
use App\Filament\Imports\SessionPartImporter;
use App\Filament\Resources\ProgramSessions\ProgramSessionResource;
use App\Models\ProgramSession;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListProgramSessions extends ListRecords
{
    protected static string $resource = ProgramSessionResource::class;

    protected function getHeaderActions(): array
    {
        $canImport = fn () => auth()->user()?->can('import', ProgramSession::class);

        return [
            ImportAction::make('importSessions')->importer(ProgramSessionImporter::class)->label('Import sessions.csv')->visible($canImport),
            ImportAction::make('importParts')->importer(SessionPartImporter::class)->label('Import session_parts.csv')->visible($canImport),
            CreateAction::make(),
        ];
    }
}
