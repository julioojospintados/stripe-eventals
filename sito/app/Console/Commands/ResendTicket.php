<?php

namespace App\Console\Commands;

use App\Jobs\SendTicketsEmail;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Rimanda per email un biglietto valido al suo intestatario (o a un altro indirizzo), con il limite di invio. */
class ResendTicket extends Command
{
    protected $signature = 'eas:resend-ticket {ticket : id o codice breve del biglietto} {--to= : indirizzo diverso da quello del biglietto}';

    protected $description = 'Rimanda l\'email con il biglietto e il QR';

    public function handle(): int
    {
        $key = (string) $this->argument('ticket');
        $ticket = Str::isUuid($key) ? Ticket::find($key) : Ticket::where('short_code', strtoupper($key))->first();
        if (! $ticket || $ticket->status->value !== 'valid') {
            $this->error('Biglietto non trovato o non valido.');

            return self::FAILURE;
        }

        $to = $this->option('to') ?: $ticket->attendee_email;
        SendTicketsEmail::dispatch($to, [$ticket->id], 'resend-'.$ticket->id.'-'.now()->format('YmdHis'));
        $this->info("Biglietto {$ticket->short_code} in coda per $to.");

        return self::SUCCESS;
    }
}
