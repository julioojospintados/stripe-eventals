<?php

namespace Database\Factories;

use App\Enums\ProgramSessionAccess;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProgramSessionFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 9999);

        return [
            'event_id' => Event::factory(),
            'code' => 'S'.$n,
            'slug' => 'session-'.$n,
            'title' => ['en' => "Workshop $n"],
            'access' => ProgramSessionAccess::Paid,
        ];
    }
}
