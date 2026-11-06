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

    protected function casts(): array
    {
        return ['name' => 'array', 'amount_cents' => 'integer', 'active' => 'boolean'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'plan_id');
    }
}
