<?php

namespace App\Services;

use App\Enums\PriceRole;
use App\Enums\TicketChannel;
use App\Enums\TicketKind;
use App\Models\Event;
use App\Models\User;

/** Cosa si vende e a che prezzo, per le pagine pubbliche: gli importi veri li ricalcola sempre CheckoutService. */
class Catalog
{
    public function __construct(private PriceResolver $prices) {}

    public function for(Event $event, ?User $user): array
    {
        $role = $user?->isMemberUntil(now()->addMinutes(CheckoutService::SESSION_MINUTES)) ? PriceRole::Member : PriceRole::Public;
        $tier = $this->prices->activeTier($event, now());

        $types = $event->ticketTypes()->with('pools', 'programSession')
            ->where('active', true)->where('channel', TicketChannel::Public)->get()
            ->map(fn ($type) => [
                'type' => $type,
                'price' => $this->prices->resolve($type, $role, $tier),
                'public' => $this->prices->resolve($type, PriceRole::Public, $tier),
                'member' => $this->prices->resolve($type, PriceRole::Member, $tier),
                'seats' => $type->seatsLeft(),
            ])
            ->filter(fn ($row) => $row['price'] !== null);

        $pass = $event->ticketTypes()->where('kind', TicketKind::FullPass)->first();
        $tiers = $event->priceTiers()->where('active', true)->get()->map(fn ($t) => [
            'tier' => $t,
            'public' => $pass ? $this->prices->resolve($pass, PriceRole::Public, $t) : null,
            'member' => $pass ? $this->prices->resolve($pass, PriceRole::Member, $t) : null,
        ]);

        return [
            'role' => $role,
            'tier' => $tier,
            'tiers' => $tiers,
            'passes' => $types->filter(fn ($row) => $row['type']->kind !== TicketKind::PaidSession)->values(),
            'workshops' => $types->filter(fn ($row) => $row['type']->kind === TicketKind::PaidSession)->values(),
        ];
    }
}
