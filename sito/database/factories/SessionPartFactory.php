<?php

namespace Database\Factories;

use App\Models\ProgramSession;
use Illuminate\Database\Eloquent\Factories\Factory;

class SessionPartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'program_session_id' => ProgramSession::factory(),
            'position' => 1,
            'title' => ['en' => 'Talk '.fake()->unique()->numberBetween(1, 9999)],
        ];
    }
}
