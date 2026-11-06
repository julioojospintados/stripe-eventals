<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventDayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'day' => fake()->unique()->dateTimeBetween('2027-04-01', '2027-04-30')->format('Y-m-d'),
        ];
    }
}
