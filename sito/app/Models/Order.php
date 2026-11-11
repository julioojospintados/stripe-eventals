<?php

namespace App\Models;

use App\Enums\CompReason;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\PriceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ordine. Ruolo, fascia, origine, persona, evento e importi si fissano alla riserva e non cambiano più (trigger
 * orders_guard); le transizioni di stato ammesse sono in OrderStatus::canTransitionTo().
 *
 * @property string $buyer_email
 * @property Carbon $created_at
 * @property-read int|string|null $refunds_sum_amount_cents
 */
#[Fillable(['event_id', 'user_id', 'buyer_email', 'status', 'review_reason', 'origin', 'price_role', 'price_tier_id', 'currency',
    'subtotal_cents', 'discount_cents', 'total_cents', 'tax_cents', 'discount_code_id', 'issued_by', 'comp_reason',
    'stripe_checkout_session_id', 'stripe_payment_intent_id', 'checkout_expires_at', 'discount_reserved', 'paid_at',
    'livemode', 'stripe_amount_total_cents', 'locale', 'billing_details', 'stripe_invoice_id', 'stripe_invoice_url'])]
class Order extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'origin' => OrderOrigin::class,
            'price_role' => PriceRole::class,
            'comp_reason' => CompReason::class,
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'tax_cents' => 'integer',
            'stripe_amount_total_cents' => 'integer',
            'checkout_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'discount_reserved' => 'boolean',
            'livemode' => 'boolean',
        ];
    }

    protected function buyerEmail(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PriceTier, $this> */
    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    /** @return BelongsTo<DiscountCode, $this> */
    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    /**
     * Chi ha emesso l'omaggio.
     *
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<CapacityHold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(CapacityHold::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** @return HasMany<Dispute, $this> */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /** @return HasMany<MemberPriceClaim, $this> */
    public function memberPriceClaims(): HasMany
    {
        return $this->hasMany(MemberPriceClaim::class);
    }
}
