<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Expired = 'expired';
    case Failed = 'failed';
    case NeedsRefund = 'needs_refund';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /**
     * Transizioni ammesse: la stessa tabella del trigger orders_guard (migrazione 100000), qui per dare un errore
     * leggibile prima di toccare il database e per gli hosting senza trigger.
     */
    public function canTransitionTo(self $to, OrderOrigin $origin = OrderOrigin::Checkout): bool
    {
        return match ($this) {
            self::Pending => in_array($to, [self::Paid, self::Processing, self::Expired, self::Cancelled, self::Failed, self::NeedsRefund], true),
            self::Processing => in_array($to, [self::Paid, self::Failed, self::NeedsRefund], true),
            // Pagamento tardivo: i soldi sono arrivati dopo la chiusura dell'ordine.
            self::Expired, self::Failed, self::Cancelled => in_array($to, [self::Paid, self::NeedsRefund], true),
            // paid → cancelled solo per gli omaggi revocati (nessun pagamento da rimborsare).
            self::Paid => in_array($to, [self::PartiallyRefunded, self::Refunded], true)
                || ($to === self::Cancelled && $origin === OrderOrigin::Complimentary),
            self::PartiallyRefunded, self::NeedsRefund => $to === self::Refunded,
            self::Refunded => false,
        };
    }
}
