<?php

namespace App\Http\Controllers;

use App\Enums\TicketKind;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Services\Catalog;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Carrello (riserva dei posti) e avvio del pagamento. I prezzi li calcola sempre CheckoutService. */
class ShopController
{
    public function index(Request $request, Catalog $catalog)
    {
        $event = Event::current() ?? abort(404);
        if (! $request->user()) {
            // Dopo il login si torna qui.
            $request->session()->put('url.intended', route('shop.index'));
        }

        $hasPass = (bool) $request->user()?->tickets()->where('status', TicketStatus::Valid)
            ->whereRelation('ticketType', 'kind', TicketKind::FullPass)->exists();

        return view('shop.index', $catalog->for($event, $request->user()) + ['event' => $event, 'hasPass' => $hasPass]);
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
        ], [], [
            'lines.*.attendees.*.name' => __('attendee name'),
            'lines.*.attendees.*.email' => __('attendee email'),
            'discount_code' => __('discount code'),
        ])->validate();

        $event = Event::current() ?? abort(404);
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
