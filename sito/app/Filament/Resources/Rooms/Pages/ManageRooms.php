<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Filament\Imports\RoomImporter;
use App\Filament\Resources\Rooms\RoomResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRooms extends ManageRecords
{
    protected static string $resource = RoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()->importer(RoomImporter::class)->label('Import rooms.csv')
                ->visible(fn () => auth()->user()?->can('program.import')),
            CreateAction::make(),
        ];
    }
}
