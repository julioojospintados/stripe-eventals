<?php

namespace App\Filament\Resources\ProgramSessions\Pages;

use App\Filament\Resources\ProgramSessions\ProgramSessionResource;
use App\Models\ProgramSession;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProgramSession extends CreateRecord
{
    use SavesWithProgramService;

    protected static string $resource = ProgramSessionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->saveSession(new ProgramSession($data));
    }
}
