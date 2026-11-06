<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

class PriceTierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'code' => fake()->unique()->lexify('tier_?????'),
            'name' => ['en' => 'Tier'],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ];
    }
}
