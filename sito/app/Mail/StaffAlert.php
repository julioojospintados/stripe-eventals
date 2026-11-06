<?php

namespace App\Mail;

use App\Enums\StaffRole;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Avviso agli admin (pagamento senza posti, rimborso fallito, job fallito): nel log e per email, in coda. */
class StaffAlert extends Mailable implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $lines */
    public function __construct(public string $title, public array $lines) {}

    public static function raise(string $title, array $lines): void
    {
        Log::warning($title, $lines);
        $admins = User::whereRelation('roles', 'name', StaffRole::Admin->value)->pluck('email');
        if ($admins->isNotEmpty()) {
            Mail::to($admins)->queue(new self($title, $lines));
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[EAS8] '.$this->title);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.staff-alert');
    }
}
