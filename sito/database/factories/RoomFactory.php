<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 9999);

        return [
            'event_id' => Event::factory(),
            'code' => "room-$n",
            'name' => ['en' => "Room $n"],
            'capacity' => 150,
            'floor' => 0,
            'position' => $n,
        ];
    }
}
