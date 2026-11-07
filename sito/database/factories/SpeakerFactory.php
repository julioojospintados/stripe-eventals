<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SpeakerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'spk-'.fake()->unique()->numberBetween(1, 99999),
            'honorific' => 'Dr.',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'affiliation' => 'Example University',
            'country' => 'PT',
            'bio' => ['en' => 'Orthodontist.'],
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
