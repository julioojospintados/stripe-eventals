<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(3),
            'title' => ['en' => 'Test congress', 'it' => 'Congresso di prova'],
            'status' => EventStatus::Published,
            'timezone' => 'Europe/Lisbon',
            'starts_on' => '2027-04-08',
            'ends_on' => '2027-04-10',
            'currency' => 'eur',
        ];
    }
}
