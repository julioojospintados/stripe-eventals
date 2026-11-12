<?php

namespace App\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\DB;

/** Biglietti validi per evento, tipo e fascia (gli omaggi non hanno fascia). */
class SalesByTypeAndTier extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Sales by ticket type and tier';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('orders.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => DB::table('tickets')
                ->join('ticket_types', 'ticket_types.id', '=', 'tickets.ticket_type_id')
                ->join('events', 'events.id', '=', 'tickets.event_id')
                ->join('orders', 'orders.id', '=', 'tickets.order_id')
                ->leftJoin('price_tiers', 'price_tiers.id', '=', 'orders.price_tier_id')
                ->where('tickets.status', 'valid')
                ->groupBy('events.slug', 'ticket_types.id', 'ticket_types.position', 'ticket_types.name', 'price_tiers.code', 'orders.origin', 'tickets.price_role')
                ->orderBy('events.slug')->orderBy('ticket_types.position')
                ->selectRaw("events.slug as event, ticket_types.name as type_name, coalesce(price_tiers.code, '—') as tier, orders.origin, tickets.price_role, count(*) as tickets")
                ->get()
                ->mapWithKeys(fn ($row, $i) => [$i => [
                    'event' => $row->event,
                    'type' => json_decode($row->type_name, true)['en'] ?? '',
                    'tier' => $row->tier, 'origin' => $row->origin, 'role' => $row->price_role, 'tickets' => $row->tickets,
                ]])->all())
            ->columns([
                TextColumn::make('event'),
                TextColumn::make('type')->label('Ticket type'),
                TextColumn::make('tier'),
                TextColumn::make('role')->label('Price'),
                TextColumn::make('origin'),
                TextColumn::make('tickets')->numeric(),
            ])
            ->paginated(false);
    }
}
