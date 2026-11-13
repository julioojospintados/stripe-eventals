<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LoginCodeMail extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('eventals.organizer.name').': '.$this->code);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.login-code');
    }
}
