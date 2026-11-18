<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CapacityHold;
use App\Models\CapacityPool;
use App\Models\DiscountCode;
use App\Models\DiscountCodeRedemption;
use App\Models\Event;
use App\Models\MemberPriceClaim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceTier;
use App\Models\ProgramSession;
use App\Models\Room;
use App\Models\Speaker;
use App\Models\Ticket;
use App\Models\TicketPrice;
use App\Models\TicketType;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Eliminazione dei dati di configurazione (prezzi, fasce, tipi, capienze, codici, sale, tracce, sessioni, speaker,
 * eventi), solo se nessun ordine, biglietto, riserva o utilizzo li usa. Ordini, biglietti, rimborsi, contestazioni,
 * membership, eventi Stripe e audit non passano di qui: non si eliminano mai. Le chiavi esterne restrict del database
 * restano l'ultima difesa (anche contro un ordine nato tra il controllo e l'eliminazione).
 */
class ConfigDeletion
{
    /** Perché il record non si può eliminare, in una frase per il pannello; null se si può. */
    public function blocker(Model $record): ?string
    {
        [$used, $linked] = $this->uses($record);

        $nonZero = fn (array $counts) => array_filter($counts, fn ($c) => $c[0] > 0);
        if ($used = $nonZero($used)) {
            return 'Usato da '.$this->list($used).': disattivalo invece di eliminarlo.';
        }
        if ($linked = $nonZero($linked)) {
            return 'Collegato a '.$this->list($linked).': elimina o sposta prima quelli.';
        }

        return null;
    }

    /** @throws DeletionBlocked */
    public function delete(Model $record, ?User $actor): void
    {
        try {
            DB::transaction(function () use ($record, $actor) {
                if ($reason = $this->blocker($record)) {
                    throw new DeletionBlocked($reason);
                }
                if ($record instanceof Event) {
                    $record->days()->delete();   // i giorni nascono e muoiono con l'evento
                }
                $record->delete();
                AuditLog::record('config.deleted', $record, $actor, ['type' => class_basename($record), 'label' => $this->label($record)]);
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            throw new DeletionBlocked('È appena stato usato da altri dati: disattivalo invece di eliminarlo.');
        }
    }

    /**
     * [usi da ordini e simili, collegamenti ad altra configurazione]: etichetta plurale => [conteggio, singolare].
     *
     * @return array{0: array<string, array{int, string}>, 1: array<string, array{int, string}>}
     */
    private function uses(Model $m): array
    {
        $id = $m->getKey();
        $orderItems = fn (string $column) => [OrderItem::where($column, $id)->distinct()->count('order_id'), 'ordine'];
        $pivot = fn (string $table, string $column, string $one) => [DB::table($table)->where($column, $id)->count(), $one];

        return match (true) {
            $m instanceof TicketPrice => [['ordini' => $orderItems('ticket_price_id')], []],
            $m instanceof PriceTier => [
                ['ordini' => [Order::where('price_tier_id', $id)->count(), 'ordine']],
                ['prezzi' => [TicketPrice::where('price_tier_id', $id)->count(), 'prezzo']],
            ],
            $m instanceof TicketType => [
                ['ordini' => $orderItems('ticket_type_id'), 'biglietti' => [Ticket::where('ticket_type_id', $id)->count(), 'biglietto']],
                ['prezzi' => [TicketPrice::where('ticket_type_id', $id)->count(), 'prezzo'],
                    'codici sconto' => $pivot('discount_code_ticket_types', 'ticket_type_id', 'codice sconto')],
            ],
            $m instanceof CapacityPool => [
                ['riserve di posti' => [CapacityHold::where('pool_id', $id)->count(), 'riserva di posti']],
                ['tipi di biglietto' => $pivot('ticket_type_pools', 'pool_id', 'tipo di biglietto')],
            ],
            $m instanceof DiscountCode => [
                ['ordini' => [Order::where('discount_code_id', $id)->count(), 'ordine'],
                    'utilizzi' => [DiscountCodeRedemption::where('discount_code_id', $id)->count(), 'utilizzo']],
                [],
            ],
            $m instanceof Room => [[], ['sessioni' => [ProgramSession::where('room_id', $id)->count(), 'sessione']]],
            $m instanceof Track => [[], ['sessioni' => [ProgramSession::where('track_id', $id)->count(), 'sessione']]],
            $m instanceof ProgramSession => [
                ['prezzi membro prenotati' => [MemberPriceClaim::where('program_session_id', $id)->count(), 'prezzo membro prenotato']],
                ['tipi di biglietto' => [TicketType::where('program_session_id', $id)->count(), 'tipo di biglietto']],
            ],
            $m instanceof Speaker => [[], [
                'sessioni' => $pivot('session_speaker', 'speaker_id', 'sessione'),
                'interventi' => $pivot('session_part_speaker', 'speaker_id', 'intervento'),
            ]],
            $m instanceof Event => [
                ['ordini' => [Order::where('event_id', $id)->count(), 'ordine'], 'biglietti' => [Ticket::where('event_id', $id)->count(), 'biglietto']],
                [
                    'tipi di biglietto' => [$m->ticketTypes()->count(), 'tipo di biglietto'],
                    'fasce' => [$m->priceTiers()->count(), 'fascia'],
                    'capienze' => [$m->capacityPools()->count(), 'capienza'],
                    'codici sconto' => [DiscountCode::where('event_id', $id)->count(), 'codice sconto'],
                    'sessioni' => [$m->programSessions()->count(), 'sessione'],
                    'sale' => [$m->rooms()->count(), 'sala'],
                    'tracce' => [$m->tracks()->count(), 'traccia'],
                ],
            ],
            default => throw new InvalidArgumentException(class_basename($m).' non si elimina.'),
        };
    }

    /** @param  array<string, array{int, string}>  $counts */
    private function list(array $counts): string
    {
        return implode(', ', array_map(fn ($many, $c) => $c[0].' '.($c[0] === 1 ? $c[1] : $many), array_keys($counts), $counts));
    }

    private function label(Model $m): string
    {
        return (string) match (true) {
            $m instanceof TicketPrice => $m->ticket_type_id.' · '.($m->price_tier_id ?? 'base').' · '.$m->price_role->value.' · '.$m->amount_cents,
            $m instanceof TicketType, $m instanceof PriceTier => $m->name['en'] ?? $m->getKey(),
            $m instanceof Event, $m instanceof Track => $m->slug,
            $m instanceof CapacityPool => $m->name,
            default => $m->code ?? $m->getKey(),   // sale, sessioni, speaker, codici sconto
        };
    }
}
