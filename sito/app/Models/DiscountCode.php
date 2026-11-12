<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Codice sconto; redeemed_count si cambia solo con l'UPDATE condizionale del Service (passo 2).
 *
 * @property string $code
 */
#[Fillable(['code', 'description', 'event_id', 'discount_type', 'percent_off', 'amount_off_cents', 'currency',
    'eligible_roles', 'combinable_with_role_price', 'applies_to_membership', 'max_redemptions', 'per_user_limit',
    'valid_from', 'valid_to', 'active', 'created_by'])]
class DiscountCode extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        // Lo sconto a importo fisso vale nella valuta del suo evento; senza evento in quella dell'installazione.
        static::saving(function (DiscountCode $code) {
            $code->currency = ($code->event_id ? Event::whereKey($code->event_id)->value('currency') : null)
                ?? $code->currency ?? config('eventals.currency');
        });
    }

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'percent_off' => 'integer',
            'amount_off_cents' => 'integer',
            'eligible_roles' => 'array',
            'combinable_with_role_price' => 'boolean',
            'applies_to_membership' => 'boolean',
            'max_redemptions' => 'integer',
            'per_user_limit' => 'integer',
            'redeemed_count' => 'integer',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'active' => 'boolean',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtoupper(trim($value)));
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Nessun tipo collegato = vale per tutti i tipi dell'evento.
     *
     * @return BelongsToMany<TicketType, $this>
     */
    public function ticketTypes(): BelongsToMany
    {
        return $this->belongsToMany(TicketType::class, 'discount_code_ticket_types');
    }

    /** @return HasMany<DiscountCodeRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountCodeRedemption::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
