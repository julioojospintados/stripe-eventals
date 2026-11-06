<?php

namespace Database\Factories;

use App\Enums\PriceRole;
use App\Models\OrderItem;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'order_id' => fn (array $a) => OrderItem::find($a['order_item_id'])->order_id,
            'event_id' => fn (array $a) => OrderItem::find($a['order_item_id'])->order->event_id,
            'ticket_type_id' => fn (array $a) => OrderItem::find($a['order_item_id'])->ticket_type_id,
            'seq' => 1,
            'price_role' => PriceRole::Public,
            'attendee_name' => 'Ana Silva',
            'attendee_email' => 'ana@example.test',
            'qr_key_id' => 'k1',
            'short_code' => fn () => Ticket::newShortCode(),
            'issued_at' => now(),
        ];
    }
}
