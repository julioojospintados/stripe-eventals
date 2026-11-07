<?php

namespace App\Filament\Imports;

use App\Models\Room;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Database\Eloquent\Model;

/** rooms.csv: code, name_en, name_it, capacity, floor, position. */
class RoomImporter extends ProgramImporter
{
    protected static ?string $model = Room::class;

    /** @var Room */
    protected ?Model $record;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('code')->requiredMapping()->rules(['required', 'regex:/^[A-Za-z0-9-]{1,30}$/']),
            ImportColumn::make('name_en')->requiredMapping()->rules(['required', 'max:100']),
            ImportColumn::make('name_it')->rules(['nullable', 'max:100']),
            ImportColumn::make('capacity')->requiredMapping()->integer()->rules(['required', 'integer', 'min:1']),
            ImportColumn::make('floor')->integer()->rules(['nullable', 'integer', 'between:-5,50']),
            ImportColumn::make('position')->integer()->rules(['nullable', 'integer', 'min:0']),
        ];
    }

    public function resolveRecord(): ?Room
    {
        if (self::isExample($this->data['code'] ?? '')) {
            return null;
        }

        return Room::firstOrNew(['event_id' => $this->event()->id, 'code' => $this->data['code']]);
    }

    public function fillRecord(): void
    {
        $this->record->fill([
            'name' => $this->texts('name'),
            'capacity' => $this->data['capacity'],
            'floor' => $this->data['floor'] ?? null,
            'position' => $this->data['position'] ?? 0,
        ]);
    }
}
