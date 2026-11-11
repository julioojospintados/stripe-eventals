<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Ticket;
use App\Services\PaymentService;
use App\Services\StripeGateway;
use App\Services\TicketService;
use Illuminate\Http\Request;

/** Area personale: ordini e biglietti solo dell'utente collegato (query dalle sue relazioni: niente IDOR). */
class AccountController
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('account.index', [
            'orders' => $user->orders()->with('items')->latest()->get(),
            'tickets' => $this->ticketsOf($request)->with('ticketType')->where('status', 'valid')->get(),
            'isMember' => $user->isMemberUntil(now()),
            'membershipInvoiceUrl' => $user->memberships()->whereNotNull('latest_invoice_url')->latest('updated_at')->value('latest_invoice_url'),
        ]);
    }

    /**
     * Ordine e pagina di successo: la pagina non è la prova del pagamento, quindi se l'ordine è ancora aperto si rilegge
     * la sessione da Stripe e si chiama lo stesso PaymentService del webhook.
     */
    public function order(Request $request, string $order, StripeGateway $stripe, PaymentService $payments)
    {
        $order = $request->user()->orders()->findOrFail($order);

        $sessionId = $request->query('session_id');
        if ($sessionId && $sessionId === $order->stripe_checkout_session_id
            && in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing], true)) {
            $payments->applySession($order, $stripe->retrieveCheckoutSession($sessionId));
            $order->refresh();
        }

        return view('account.order', ['order' => $order->load('items', 'tickets.ticketType')]);
    }

    public function qr(Request $request, string $ticket, TicketService $tickets)
    {
        $ticket = $this->ticketsOf($request)->where('status', 'valid')->findOrFail($ticket);

        return response($tickets->qrPng($ticket))->header('Content-Type', 'image/png')->header('Cache-Control', 'private, no-store');
    }

    /** Biglietti comprati dall'utente o intestati a lui. */
    private function ticketsOf(Request $request)
    {
        $id = $request->user()->id;

        return Ticket::where(fn ($q) => $q->where('attendee_user_id', $id)->orWhereRelation('order', 'user_id', $id));
    }
}
