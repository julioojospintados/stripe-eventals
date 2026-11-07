<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Jobs\SendTicketsEmail;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Biglietti nominativi e QR firmato (docs/architettura.md §11.1). Il QR contiene ticket_id, qr_version, event_id e
 * qr_key_id, firmati Ed25519 con la chiave privata del .env; niente nome. Chi legge il QR verifica con la chiave
 * pubblica e confronta qr_version con quella dell'export.
 */
class TicketService
{
    private const PREFIX = 'EAS1';

    /** Un biglietto per nominativo; si può rilanciare (unique su riga e numero). Va chiamato sotto lock dell'ordine. */
    public function issue(Order $order): void
    {
        foreach ($order->items()->with('ticketType')->get() as $item) {
            foreach ($item->attendees as $i => $attendee) {
                Ticket::firstOrCreate(['order_item_id' => $item->id, 'seq' => $i + 1], [
                    'event_id' => $order->event_id,
                    'order_id' => $order->id,
                    'ticket_type_id' => $item->ticket_type_id,
                    'price_role' => $item->price_role,
                    'attendee_name' => $attendee['name'],
                    'attendee_email' => $attendee['email'],
                    'attendee_user_id' => User::where('email', mb_strtolower($attendee['email']))->value('id'),
                    'qr_key_id' => config('tickets.qr.signing_key_id'),
                    'short_code' => Ticket::newShortCode(),
                    'issued_at' => now(),
                ]);
            }
        }
    }

    public const MAX_CORRECTIONS = 3;

    /** Rimanda per email un biglietto valido al suo intestatario o a un altro indirizzo (pannello e eas:resend-ticket). */
    public function resend(Ticket $ticket, ?string $to = null, ?User $staff = null): string
    {
        if ($ticket->status !== TicketStatus::Valid) {
            throw new ShopException('ticket_not_valid');
        }
        $to = $to ? mb_strtolower(trim($to)) : $ticket->attendee_email;
        SendTicketsEmail::dispatch($to, [$ticket->id], 'resend-'.$ticket->id.'-'.now()->format('YmdHis'));
        AuditLog::record('ticket.resent', $ticket, $staff, ['to' => $to]);

        return $to;
    }

    /**
     * Correzione di un refuso nel nominativo, solo admin (architettura §6.7): contatore, autore e data nella stessa
     * modifica (il trigger tickets_guard lo pretende), riga di audit nella stessa transazione. Se cambia l'email il
     * QR cambia versione e il biglietto parte verso il nuovo indirizzo.
     */
    public function correctAttendee(Ticket $ticket, User $admin, string $name, string $email, string $reason): Ticket
    {
        return DB::transaction(function () use ($ticket, $admin, $name, $email, $reason) {
            $ticket = Ticket::lockForUpdate()->findOrFail($ticket->id);
            if ($ticket->attendee_corrections_count >= self::MAX_CORRECTIONS) {
                throw new ShopException('too_many_corrections');
            }

            $old = ['name' => $ticket->attendee_name, 'email' => $ticket->attendee_email];
            $ticket->attendee_name = trim($name);
            $ticket->attendee_email = $email;
            $emailChanged = $ticket->attendee_email !== $old['email'];
            if ($emailChanged) {
                $ticket->qr_version++;
                $ticket->attendee_user_id = User::where('email', $ticket->attendee_email)->value('id');
            }
            $ticket->attendee_corrections_count++;
            $ticket->attendee_corrected_at = now();
            $ticket->attendee_corrected_by = $admin->id;
            $ticket->save();

            AuditLog::record('ticket.attendee_corrected', $ticket, $admin, [
                'old' => $old, 'new' => ['name' => $ticket->attendee_name, 'email' => $ticket->attendee_email], 'reason' => $reason,
            ]);
            if ($emailChanged && $ticket->status === TicketStatus::Valid) {
                SendTicketsEmail::dispatch($ticket->attendee_email, [$ticket->id], "correction-{$ticket->id}-v{$ticket->qr_version}")->afterCommit();
            }

            return $ticket;
        });
    }

    public function qrPayload(Ticket $ticket): string
    {
        $message = json_encode([
            't' => $ticket->id, 'v' => $ticket->qr_version, 'e' => $ticket->event_id, 'k' => config('tickets.qr.signing_key_id'),
        ]);
        $signature = sodium_crypto_sign_detached($message, $this->secretKey());

        return self::PREFIX.'.'.$this->b64($message).'.'.$this->b64($signature);
    }

    /**
     * Dati del QR se la firma è valida con la chiave indicata in 'k' (attiva o precedente), altrimenti null.
     * Il QR rigenerato è firmato sempre con la chiave attiva; qr_key_id sul biglietto resta quella dell'emissione.
     */
    public function verify(string $payload): ?array
    {
        $parts = explode('.', $payload);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }
        $message = base64_decode(strtr($parts[1], '-_', '+/'));
        $signature = base64_decode(strtr($parts[2], '-_', '+/'));
        $publicKey = $this->publicKeys()[json_decode((string) $message, true)['k'] ?? null] ?? null;

        return $publicKey !== null && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($signature, $message, $publicKey)
            ? json_decode($message, true) : null;
    }

    public function qrPng(Ticket $ticket): string
    {
        return (new Writer(new GDLibRenderer(360)))->writeString($this->qrPayload($ticket));
    }

    private function secretKey(): string
    {
        $key = base64_decode((string) config('tickets.qr.signing_private_key'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('QR_SIGNING_PRIVATE_KEY mancante o non valida (vedi .env.example).');
        }

        return $key;
    }

    /** key_id => chiave pubblica: l'attiva (dalla privata) più le precedenti da QR_PREVIOUS_PUBLIC_KEYS. */
    private function publicKeys(): array
    {
        $keys = [];
        foreach (array_filter(explode(',', (string) config('tickets.qr.previous_public_keys'))) as $pair) {
            [$id, $key] = array_map('trim', explode(':', $pair, 2)) + [1 => ''];
            $key = base64_decode($key, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new RuntimeException("QR_PREVIOUS_PUBLIC_KEYS: chiave '$id' non valida (vedi .env.example).");
            }
            $keys[$id] = $key;
        }
        $keys[config('tickets.qr.signing_key_id')] = sodium_crypto_sign_publickey_from_secretkey($this->secretKey());

        return $keys;
    }

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
