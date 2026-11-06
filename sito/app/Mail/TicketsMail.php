<?php

namespace App\Mail;

use App\Services\TicketService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/** I biglietti con il QR in PNG dentro l'email (immagini incorporate, non link esterni). */
class TicketsMail extends Mailable
{
    public string $lang;

    public function __construct(public Collection $tickets)
    {
        $this->lang = $tickets->first()->order->locale;
        $this->locale($this->lang);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->lang === 'it' ? 'I tuoi biglietti EAS8' : 'Your EAS8 tickets');
    }

    public function content(): Content
    {
        $service = app(TicketService::class);

        return new Content(view: 'mail.tickets', with: [
            'qr' => $this->tickets->mapWithKeys(fn ($t) => [$t->id => $service->qrPng($t)]),
        ]);
    }
}
