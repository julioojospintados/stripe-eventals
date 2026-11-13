<?php

namespace App\Filament\Widgets;

use App\Models\CapacityPool;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Posti rimasti per pool (giorni e workshop). */
class SeatsLeft extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Seats left';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('capacity_pools.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CapacityPool::query()->with('event')->orderBy('event_id')->orderBy('name'))
            ->columns([
                TextColumn::make('event.slug')->label('Event'),
                TextColumn::make('name'),
                TextColumn::make('capacity')->numeric(),
                TextColumn::make('confirmed')->label('Sold')->numeric(),
                TextColumn::make('held')->label('In checkout')->numeric(),
                TextColumn::make('left')->label('Left')->numeric()->weight('bold')
                    ->state(fn (CapacityPool $record) => $record->capacity - $record->held - $record->confirmed),
            ])
            ->paginated(false);
    }
}
