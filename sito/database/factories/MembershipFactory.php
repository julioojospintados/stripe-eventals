<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => MembershipPlan::factory(),
            'stripe_subscription_id' => 'sub_'.fake()->unique()->regexify('[A-Za-z0-9]{14}'),
            'stripe_customer_id' => 'cus_'.fake()->regexify('[A-Za-z0-9]{14}'),
            'status' => MembershipStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addYear(),
            'activated_at' => now(),
        ];
    }
}
