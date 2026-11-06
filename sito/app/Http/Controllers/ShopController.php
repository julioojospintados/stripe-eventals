<?php

namespace App\Http\Controllers;

use App\Enums\PriceRole;
use App\Enums\TicketChannel;
use App\Models\Event;
use App\Services\CheckoutService;
use App\Services\PriceResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Carrello (riserva dei posti) e avvio del pagamento. I prezzi li calcola sempre CheckoutService. */
class ShopController
{
    public function index(Request $request, PriceResolver $prices)
    {
        $event = Event::published()->latest('starts_on')->firstOrFail();
        $role = $request->user()?->isMemberUntil(now()->addMinutes(CheckoutService::SESSION_MINUTES)) ? PriceRole::Member : PriceRole::Public;
        $tier = $prices->activeTier($event, now());

        $types = $event->ticketTypes()->where('active', true)->where('channel', TicketChannel::Public)->get()
            ->map(fn ($type) => ['type' => $type, 'price' => $prices->resolve($type, $role, $tier)])
            ->filter(fn ($row) => $row['price'] !== null);

        return view('shop.index', compact('event', 'types', 'role'));
    }

    /** lines[<ticket_type_id>][attendees][][name|email]: le righe vuote del form non contano. */
    public function reserve(Request $request, CheckoutService $checkout)
    {
        $lines = collect($request->input('lines', []))
            ->map(fn ($line, $typeId) => [
                'ticket_type_id' => (string) $typeId,
                'attendees' => array_values(array_filter((array) ($line['attendees'] ?? []), fn ($a) => filled($a['name'] ?? null) || filled($a['email'] ?? null))),
            ])
            ->filter(fn ($line) => $line['attendees'] !== [])
            ->values()->all();

        $data = Validator::make(['lines' => $lines] + $request->only('discount_code'), [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ticket_type_id' => ['required', 'uuid'],
            'lines.*.attendees' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.attendees.*.name' => ['required', 'string', 'max:200'],
            'lines.*.attendees.*.email' => ['required', 'email', 'max:254'],
            'discount_code' => ['nullable', 'string', 'max:40'],
        ])->validate();

        $event = Event::published()->latest('starts_on')->firstOrFail();
        $order = $checkout->reserve($request->user(), $event, $data['lines'], $data['discount_code'] ?? null, app()->getLocale());

        return $request->expectsJson()
            ? response()->json(['order_id' => $order->id, 'total_cents' => $order->total_cents], 201)
            : redirect()->route('account.orders.show', $order);
    }

    /** I nominativi non si cambiano dopo l'acquisto: si conferma prima di pagare. */
    public function checkout(Request $request, string $order, CheckoutService $checkout)
    {
        $request->validate(['attendees_confirmed' => ['accepted']]);
        $url = $checkout->startCheckout($request->user()->orders()->findOrFail($order));

        return $request->expectsJson() ? response()->json(['url' => $url]) : redirect()->away($url);
    }
}
