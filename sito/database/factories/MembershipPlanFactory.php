<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class MembershipPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => ['en' => 'Annual membership'],
            'amount_cents' => 20000,
            'currency' => 'eur',
            'billing_interval' => 'year',
        ];
    }
}
