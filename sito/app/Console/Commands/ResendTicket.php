<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Rimanda per email un biglietto valido al suo intestatario (o a un altro indirizzo), con il limite di invio. */
class ResendTicket extends Command
{
    protected $signature = 'eventals:resend-ticket {ticket : id o codice breve del biglietto} {--to= : indirizzo diverso da quello del biglietto}';

    /** @var list<string> Nome di prima del sistema multi-evento, deprecato: resta per cron e script esistenti. */
    protected $aliases = ['eas:resend-ticket'];

    protected $description = 'Rimanda l\'email con il biglietto e il QR';

    public function handle(TicketService $tickets): int
    {
        $key = (string) $this->argument('ticket');
        $ticket = Str::isUuid($key) ? Ticket::find($key) : Ticket::where('short_code', strtoupper($key))->first();
        if (! $ticket || $ticket->status !== TicketStatus::Valid) {
            $this->error('Biglietto non trovato o non valido.');

            return self::FAILURE;
        }

        $to = $tickets->resend($ticket, $this->option('to'));
        $this->info("Biglietto {$ticket->short_code} in coda per $to.");

        return self::SUCCESS;
    }
}
