<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Export CSV del pannello (docs/architettura.md §11.2, §13.3): biglietti validi per il partner del check-in, ordini
 * per la contabilità, iscritti alla newsletter con consenso. Fino a $syncLimit righe si scaricano subito, oltre si
 * generano in coda in un file privato dello staff (storage/app/private/exports/<id utente>/), tenuto 7 giorni.
 * Biglietti e ordini si esportano per evento ($eventId); null = tutti gli eventi.
 */
class CsvExports
{
    /** Righe oltre le quali l'export va in coda (proprietà e non costante: i test la abbassano). */
    public static int $syncLimit = 2000;

    public const KEEP_DAYS = 7;

    /** Tipo di export => permesso che serve. */
    public const PERMISSIONS = [
        'tickets' => 'attendees.export',
        'orders' => 'orders.export',
        'consents' => 'consents.export',
    ];

    public const HEADERS = [
        'tickets' => ['ticket_id', 'qr_version', 'short_code', 'attendee_name', 'ticket_type', 'ticket_name', 'days', 'status'],
        'orders' => ['order_id', 'created_at', 'paid_at', 'status', 'origin', 'buyer_email', 'price_role', 'price_tier',
            'currency', 'subtotal', 'discount', 'total', 'refunded', 'discount_code', 'stripe_payment_intent_id'],
        'consents' => ['email', 'granted_at', 'source', 'locale', 'text_version'],
    ];

    public function count(string $type, ?string $eventId = null): int
    {
        return match ($type) {
            'tickets' => Ticket::where('status', TicketStatus::Valid)->when($eventId, fn ($q) => $q->where('event_id', $eventId))->count(),
            'orders' => Order::when($eventId, fn ($q) => $q->where('event_id', $eventId))->count(),
            'consents' => $this->consents()->count(),
            default => throw new InvalidArgumentException($type),
        };
    }

    /** Scrive intestazione e righe su un file aperto (php://output per lo scaricamento diretto). */
    public function write(string $type, $handle, ?string $eventId = null): void
    {
        fputcsv($handle, self::HEADERS[$type]);
        foreach ($this->rows($type, $eventId) as $row) {
            fputcsv($handle, array_map($this->safe(...), $row));
        }
    }

    public function filename(string $type): string
    {
        return $type.'-'.now()->format('Ymd-His').'-'.strtolower(Str::random(8)).'.csv';
    }

    /** Percorso privato del file di uno staff (disco local). */
    public static function path(User $user, string $filename = ''): string
    {
        return "exports/{$user->id}/".$filename;
    }

    public function store(string $type, User $user, string $filename, ?string $eventId = null): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::path($user));
        $handle = fopen($disk->path(self::path($user, $filename)), 'w');
        try {
            $this->write($type, $handle, $eventId);
        } finally {
            fclose($handle);
        }
    }

    public function audit(string $type, User $user, int $rows, string $mode, ?string $eventId = null): void
    {
        AuditLog::record('export.'.$type, null, $user, array_filter(['rows' => $rows, 'mode' => $mode, 'event_id' => $eventId], fn ($v) => $v !== null));
    }

    /** @return iterable<list<string|int|null>> */
    private function rows(string $type, ?string $eventId): iterable
    {
        if ($type === 'tickets') {
            $days = $this->daysByType();
            foreach (Ticket::with('ticketType')->where('status', TicketStatus::Valid)->when($eventId, fn ($q) => $q->where('event_id', $eventId))->orderBy('short_code')->lazy() as $t) {
                yield [$t->id, $t->qr_version, $t->short_code, $t->attendee_name, $t->ticketType->kind->value,
                    $t->ticketType->name['en'] ?? '', $days[$t->ticket_type_id] ?? '', $t->status->value];
            }
        } elseif ($type === 'orders') {
            $orders = Order::with('priceTier', 'discountCode')->when($eventId, fn ($q) => $q->where('event_id', $eventId))->withSum(['refunds' => fn ($q) => $q->where('status', '!=', 'failed')], 'amount_cents');
            foreach ($orders->orderBy('created_at')->lazy() as $o) {
                yield [$o->id, $o->created_at->toIso8601String(), $o->paid_at?->toIso8601String(), $o->status->value,
                    $o->origin->value, $o->buyer_email, $o->price_role->value, $o->priceTier?->code, $o->currency,
                    $this->amount($o->subtotal_cents), $this->amount($o->discount_cents), $this->amount($o->total_cents),
                    $this->amount((int) $o->refunds_sum_amount_cents), $o->discountCode?->code, $o->stripe_payment_intent_id];
            }
        } else {
            foreach ($this->consents()->orderBy('email')->cursor() as $c) {
                yield [$c->email, $c->occurred_at, $c->source, $c->locale, $c->text_version];
            }
        }
    }

    /** Ultima riga per email (consensi solo in aggiunta), tenuta solo se è un consenso dato. */
    private function consents()
    {
        $latest = DB::table('consents')->where('purpose', 'newsletter')
            ->select('*', DB::raw('row_number() over (partition by email order by occurred_at desc) as rn'));

        return DB::query()->fromSub($latest, 'c')->where('rn', 1)->where('granted', true);
    }

    /** Giorni ammessi per tipo: il giorno del tipo, il giorno della sessione, altrimenti tutti i giorni dell'evento. */
    private function daysByType(): array
    {
        return TicketType::with('event.days', 'eventDay', 'programSession.eventDay')->get()
            ->mapWithKeys(function (TicketType $type) {
                $day = $type->eventDay ?? $type->programSession?->eventDay;
                $days = $day ? collect([$day]) : $type->event->days;

                return [$type->id => $days->map(fn ($d) => $d->day->toDateString())->implode(';')];
            })->all();
    }

    private function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** Contro la formula injection nei fogli di calcolo: un testo che inizia con = + - @ diventa testo. */
    private function safe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && str_contains('=+-@', $value[0]) && ! is_numeric($value) ? "'".$value : $value;
    }
}
