<?php

namespace App\Filament\Resources\CapacityPools\Pages;

use App\Filament\Resources\CapacityPools\CapacityPoolResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCapacityPools extends ManageRecords
{
    protected static string $resource = CapacityPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
