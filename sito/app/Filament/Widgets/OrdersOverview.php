<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Cruscotto: ordini da rimborsare (pagati senza posti), ordini pagati, biglietti validi. */
class OrdersOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('orders.view');
    }

    protected function getStats(): array
    {
        $needsRefund = Order::where('status', OrderStatus::NeedsRefund)->count();

        return [
            Stat::make('Orders in needs_refund', $needsRefund)
                ->description($needsRefund ? 'Paid without seats: the automatic refund is running' : 'Nothing to refund')
                ->color($needsRefund ? 'danger' : 'success')
                ->url(OrderResource::getUrl('index', ['filters' => ['status' => ['value' => OrderStatus::NeedsRefund->value]]])),
            Stat::make('Paid orders', Order::where('status', OrderStatus::Paid)->count()),
            Stat::make('Valid tickets', Ticket::where('status', TicketStatus::Valid)->count()),
        ];
    }
}
