<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'stripe_price_id', 'amount_cents', 'currency', 'billing_interval', 'active'])]
class MembershipPlan extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::creating(fn (MembershipPlan $plan) => $plan->currency ??= config('eventals.currency'));
    }

    protected function casts(): array
    {
        return ['name' => 'array', 'amount_cents' => 'integer', 'active' => 'boolean'];
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'plan_id');
    }
}
