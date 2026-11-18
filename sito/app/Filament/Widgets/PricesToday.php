<?php

namespace App\Filament\Widgets;

use App\Enums\EventStatus;
use App\Enums\PriceRole;
use App\Enums\TicketChannel;
use App\Models\TicketType;
use App\Services\PriceResolver;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * «Prezzo oggi» per tipo di biglietto pubblico e ruolo, con lo stesso PriceResolver del checkout: fascia attiva adesso,
 * prezzo (tipo, fascia, ruolo) o base, prezzo membro solo se non supera il pubblico, senza prezzo pubblico non si compra.
 */
class PricesToday extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Prezzo oggi';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('ticket_prices.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('event')->label('Evento'),
                TextColumn::make('type')->label('Tipo di biglietto'),
                TextColumn::make('on_sale')->label('In vendita')->badge()
                    ->color(fn (string $state) => $state === 'sì' ? 'success' : 'warning'),
                TextColumn::make('role')->label('Ruolo'),
                TextColumn::make('tier')->label('Fascia attiva'),
                TextColumn::make('price')->label('Prezzo')->badge()
                    ->color(fn (string $state) => str_starts_with($state, 'Nessun prezzo') ? 'danger' : 'gray'),
            ])
            ->paginated(false);
    }

    private function rows(): array
    {
        $prices = app(PriceResolver::class);
        $rows = [];
        $types = TicketType::with('event')->where('channel', TicketChannel::Public)
            ->whereHas('event', fn ($q) => $q->whereIn('status', [EventStatus::Draft, EventStatus::Published]))
            ->orderBy('event_id')->orderBy('position')->get();

        foreach ($types as $type) {
            $event = $type->event;
            $tier = $prices->activeTier($event, now());
            $public = $prices->resolve($type, PriceRole::Public, $tier);
            $member = $prices->resolve($type, PriceRole::Member, $tier);
            $memberPays = $member?->price_role === PriceRole::Member && $public && $member->amount_cents <= $public->amount_cents ? $member : $public;
            $base = [
                'event' => $event->slug,
                'type' => $type->name['en'] ?? '',
                'on_sale' => $type->isOnSale() ? 'sì' : $this->whyNotOnSale($type),
                'tier' => $tier ? ($tier->name['en'] ?? $tier->code).' fino al '.$tier->ends_at->timezone($event->timezone)->format('d/m/Y H:i') : 'nessuna: vale il prezzo base',
            ];
            $none = 'Nessun prezzo: non acquistabile';
            $rows[] = $base + ['role' => 'pubblico', 'price' => $public ? money($public->amount_cents, $event->currency) : $none];
            $rows[] = $base + ['role' => 'membro', 'price' => match (true) {
                ! $public => $none,
                $memberPays->price_role === PriceRole::Member => money($memberPays->amount_cents, $event->currency).' (prezzo membro)',
                default => money($public->amount_cents, $event->currency).' (come il pubblico)',
            }];
        }

        return $rows;
    }

    private function whyNotOnSale(TicketType $type): string
    {
        return match (true) {
            ! $type->active => 'no: spento',
            ! $type->sales_start => 'no: senza data d\'inizio',
            $type->sales_start->isFuture() => 'no: apre il '.$type->sales_start->timezone($type->event->timezone)->format('d/m/Y H:i'),
            $type->sales_end && ! $type->sales_end->isFuture() => 'no: chiuso',
            default => 'no: evento non pubblicato o vendite dell\'evento chiuse',
        };
    }
}
