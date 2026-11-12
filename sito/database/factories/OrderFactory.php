<?php

namespace Database\Factories;

use App\Enums\CompReason;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\PriceRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'buyer_email' => fake()->safeEmail(),
            'status' => OrderStatus::Pending,
            'origin' => OrderOrigin::Checkout,
            'price_role' => PriceRole::Public,
            'subtotal_cents' => 10000,
            'discount_cents' => 0,
            'total_cents' => 10000,
            'livemode' => false,
        ];
    }

    public function paid(): static
    {
        return $this->state(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    }

    /** Omaggio emesso dal pannello: costo zero, nasce già pagato. */
    public function complimentary(?User $issuer = null): static
    {
        return $this->state([
            'origin' => OrderOrigin::Complimentary,
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'subtotal_cents' => 0,
            'total_cents' => 0,
            'issued_by' => $issuer ?? User::factory(),
            'comp_reason' => CompReason::Speaker,
        ]);
    }
}
