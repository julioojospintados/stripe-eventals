<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\PriceRole;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\PriceTier;
use App\Models\TicketPrice;
use App\Models\TicketType;
use DateTimeInterface;

/**
 * Prezzi (docs/architettura.md §6.3, §6.4): fascia attiva → prezzo (tipo, fascia, ruolo) → prezzo base (tipo, ruolo)
 * → per il membro senza prezzo, lo stesso calcolo col ruolo pubblico → nessun prezzo = non acquistabile.
 */
class PriceResolver
{
    public function activeTier(Event $event, DateTimeInterface $at): ?PriceTier
    {
        return $event->priceTiers()
            ->where('active', true)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->first();
    }

    /** Il prezzo che si paga con quel ruolo; il suo price_role dice se è davvero un prezzo membro. */
    public function resolve(TicketType $type, PriceRole $role, ?PriceTier $tier): ?TicketPrice
    {
        $roles = $role === PriceRole::Member ? [PriceRole::Member, PriceRole::Public] : [PriceRole::Public];

        foreach ($roles as $r) {
            $prices = $type->prices()->where('price_role', $r)->get();
            $price = ($tier ? $prices->firstWhere('price_tier_id', $tier->id) : null)
                ?? $prices->firstWhere('price_tier_id', null);
            if ($price) {
                return $price;
            }
        }

        return null;
    }

    /** Sconto per unità in centesimi; 0 se il codice non vale per quel tipo. */
    public function unitDiscount(DiscountCode $code, TicketType $type, int $listCents): int
    {
        $types = $code->ticketTypes->modelKeys();
        if ($types !== [] && ! in_array($type->id, $types, true)) {
            return 0;
        }

        $off = $code->discount_type === DiscountType::Percent
            ? intdiv($listCents * $code->percent_off + 50, 100)
            : $code->amount_off_cents;

        return min($off, $listCents);
    }
}
