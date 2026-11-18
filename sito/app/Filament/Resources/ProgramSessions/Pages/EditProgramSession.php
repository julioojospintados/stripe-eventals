<?php

namespace App\Filament\Resources\ProgramSessions\Pages;

use App\Filament\Actions\DeleteConfig;
use App\Filament\Resources\ProgramSessions\ProgramSessionResource;
use App\Models\ProgramSession;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProgramSession extends EditRecord
{
    use SavesWithProgramService;

    protected static string $resource = ProgramSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteConfig::make()->successRedirectUrl(ProgramSessionResource::getUrl())];
    }

    /** @param  ProgramSession  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->saveSession($record->fill($data));
    }
}
