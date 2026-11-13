<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Factories\Factory;

class DiscountCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('CODE-####'),
            'discount_type' => DiscountType::Percent,
            'percent_off' => 10,
        ];
    }
}
