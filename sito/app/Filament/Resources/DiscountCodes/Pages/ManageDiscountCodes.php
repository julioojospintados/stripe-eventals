<?php

namespace App\Filament\Resources\DiscountCodes\Pages;

use App\Filament\Resources\DiscountCodes\DiscountCodeResource;
use App\Models\DiscountCode;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDiscountCodes extends ManageRecords
{
    protected static string $resource = DiscountCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])
                ->after(fn (DiscountCode $record) => DiscountCodeResource::audit('discount_code.created', $record)),
        ];
    }
}
