<?php

namespace Database\Factories;

use App\Enums\PriceRole;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_type_id' => TicketType::factory(),
            'price_tier_id' => null,
            'price_role' => PriceRole::Public,
            'amount_cents' => 10000,
        ];
    }
}
