<?php

namespace App\Models;

use App\Enums\PriceRole;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Biglietto nominativo. Il nominativo non cambia (nessun cambio di nome): solo un admin corregge un refuso, al massimo
 * 3 volte, con contatore, autore e data (trigger tickets_guard). I biglietti non si cancellano.
 *
 * @property string $attendee_email
 */
#[Fillable(['event_id', 'order_id', 'order_item_id', 'seq', 'ticket_type_id', 'price_role', 'attendee_name',
    'attendee_email', 'attendee_user_id', 'host_ticket_id', 'status', 'qr_version', 'qr_key_id', 'short_code',
    'issued_at', 'cancelled_at'])]
class Ticket extends Model
{
    use HasFactory, HasUuids;

    /** Alfabeto del codice breve: senza 0, O, 1, I, L (CHECK tickets_short_code_format). */
    public const SHORT_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected function casts(): array
    {
        return [
            'price_role' => PriceRole::class,
            'status' => TicketStatus::class,
            'seq' => 'integer',
            'qr_version' => 'integer',
            'attendee_corrections_count' => 'integer',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'attendee_corrected_at' => 'datetime',
        ];
    }

    public static function newShortCode(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::SHORT_CODE_ALPHABET[random_int(0, strlen(self::SHORT_CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    protected function attendeeEmail(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<TicketType, $this> */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function attendeeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendee_user_id');
    }

    /**
     * Pass a cui è legato un accompagnatore.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function hostTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'host_ticket_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function companions(): HasMany
    {
        return $this->hasMany(Ticket::class, 'host_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendee_corrected_by');
    }
}
