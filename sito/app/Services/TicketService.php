<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
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

    public function qrPayload(Ticket $ticket): string
    {
        $message = json_encode([
            't' => $ticket->id, 'v' => $ticket->qr_version, 'e' => $ticket->event_id, 'k' => $ticket->qr_key_id,
        ]);
        $signature = sodium_crypto_sign_detached($message, $this->secretKey());

        return self::PREFIX.'.'.$this->b64($message).'.'.$this->b64($signature);
    }

    /** Dati del QR se la firma è valida, altrimenti null (usato dai test e dal check-in del partner). */
    public function verify(string $payload): ?array
    {
        $parts = explode('.', $payload);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }
        $message = base64_decode(strtr($parts[1], '-_', '+/'));
        $signature = base64_decode(strtr($parts[2], '-_', '+/'));
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($this->secretKey());

        return strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
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

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
