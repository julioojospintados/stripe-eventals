<?php

namespace Database\Factories;

use App\Enums\PriceRole;
use App\Models\Order;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    /** Importi coerenti con i CHECK: unità = listino − sconto, totale = unità × quantità, un nominativo per unità. */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'ticket_type_id' => fn (array $a) => TicketType::factory()->create(['event_id' => Order::find($a['order_id'])->event_id]),
            'quantity' => 1,
            'price_role' => PriceRole::Public,
            'list_unit_amount_cents' => 10000,
            'unit_discount_cents' => 0,
            'unit_amount_cents' => fn (array $a) => $a['list_unit_amount_cents'] - $a['unit_discount_cents'],
            'total_cents' => fn (array $a) => $a['unit_amount_cents'] * $a['quantity'],
            'stripe_line_name' => 'Full pass',
            'attendees' => fn (array $a) => array_fill(0, $a['quantity'], ['name' => 'Ana Silva', 'email' => 'ana@example.test']),
        ];
    }
}
