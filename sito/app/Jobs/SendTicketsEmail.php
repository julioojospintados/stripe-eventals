<?php

namespace App\Jobs;

use App\Mail\StaffAlert;
use App\Mail\TicketsMail;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email con i biglietti e i QR, in coda con il limite di invio della casella SMTP (RateLimiter "mail",
 * MAIL_RATE_PER_MINUTE). La dedupe_key in sent_messages evita un secondo invio se il job si ripete.
 */
class SendTicketsEmail implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $ticketIds */
    public function __construct(public string $to, public array $ticketIds, public string $dedupeKey) {}

    /** All'acquirente tutti i biglietti, a ogni altro partecipante i suoi. */
    public static function dispatchForOrder(Order $order): void
    {
        $tickets = $order->tickets()->get(['id', 'attendee_email']);
        $recipients = $tickets->groupBy('attendee_email')->map->pluck('id');
        $recipients[$order->buyer_email] = $tickets->pluck('id');

        foreach ($recipients as $email => $ids) {
            self::dispatch($email, $ids->all(), "tickets-{$order->id}-".hash('sha256', $email))->afterCommit();
        }
    }

    public function middleware(): array
    {
        return [new RateLimited('mail')];
    }

    /** Il limite di invio rimette il job in coda: conta il tempo, non i tentativi. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(): void
    {
        if (DB::table('sent_messages')->where('dedupe_key', $this->dedupeKey)->exists()) {
            return;
        }

        $tickets = Ticket::with('ticketType', 'event', 'order')->whereIn('id', $this->ticketIds)->where('status', 'valid')->get();
        if ($tickets->isEmpty()) {
            return;
        }

        Mail::to($this->to)->send(new TicketsMail($tickets));

        DB::table('sent_messages')->insertOrIgnore([
            'dedupe_key' => $this->dedupeKey, 'mailable' => 'tickets', 'to_hash' => hash('sha256', $this->to), 'sent_at' => now(),
        ]);
    }

    /** Dopo un giorno di tentativi i biglietti non sono partiti: lo staff li reinvia dal pannello. */
    public function failed(Throwable $e): void
    {
        StaffAlert::raise('Email dei biglietti non inviata', ["{$this->dedupeKey}: {$e->getMessage()}", 'Reinvia dal pannello (Tickets, Resend ticket).']);
    }
}
