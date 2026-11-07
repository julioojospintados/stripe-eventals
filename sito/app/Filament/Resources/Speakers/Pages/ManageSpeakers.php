<?php

namespace App\Filament\Resources\Speakers\Pages;

use App\Filament\Imports\SpeakerImporter;
use App\Filament\Resources\Speakers\SpeakerResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSpeakers extends ManageRecords
{
    protected static string $resource = SpeakerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()->importer(SpeakerImporter::class)->label('Import speakers.csv')
                ->visible(fn () => auth()->user()?->can('program.import')),
            CreateAction::make(),
        ];
    }
}
