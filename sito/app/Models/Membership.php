<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Membership annuale; dopo l'attivazione la aggiornano solo gli eventi Stripe. */
#[Fillable(['user_id', 'plan_id', 'stripe_subscription_id', 'stripe_customer_id', 'status', 'current_period_start',
    'current_period_end', 'cancel_at_period_end', 'canceled_at', 'ended_at', 'activated_at', 'last_stripe_event_at'])]
class Membership extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_stripe_event_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }
}
