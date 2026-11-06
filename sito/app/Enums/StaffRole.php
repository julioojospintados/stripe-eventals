<?php

namespace App\Enums;

/**
 * Ruoli dello staff (spatie/laravel-permission) e i loro permessi, da docs/architettura.md §5.3.
 * Membro non è un ruolo: si deriva da memberships. Dopo una modifica: php artisan db:seed --class=RolesAndPermissionsSeeder
 */
enum StaffRole: string
{
    case Admin = 'admin';
    case Commerciale = 'commerciale';
    case Organizzatore = 'organizzatore';

    /** Tutti i permessi, con il significato. */
    public const PERMISSIONS = [
        'event.view' => 'Evento, date, giorni e (Fase 2) programma: lettura',
        'event.manage' => 'Evento, date, giorni e (Fase 2) programma: modifica',
        'sales.view' => 'Tipi di biglietto, fasce, prezzi, capienze: lettura',
        'sales.manage' => 'Tipi di biglietto, fasce, prezzi, capienze: modifica',
        'discount_codes.manage' => 'Codici sconto: creazione e modifica',
        'invite_codes.view' => 'Codici d\'invito per espositori: lettura',
        'invite_codes.manage' => 'Codici d\'invito per espositori: creazione e modifica',
        'orders.view' => 'Ordini e biglietti: lettura senza importi',
        'orders.view_amounts' => 'Ordini, pagamenti e biglietti: lettura con importi',
        'orders.manage' => 'Ordini, pagamenti e biglietti: azioni',
        'complimentary.view' => 'Omaggi per speaker e staff: lettura',
        'complimentary.manage' => 'Omaggi per speaker e staff: emissione e revoca',
        'refunds.manage' => 'Rimborsi',
        'tickets.correct_attendee' => 'Correzione di un refuso nel nominativo',
        'accounting.manage' => 'Fatture, export contabili, riconciliazione',
        'memberships.view' => 'Membership: lettura (le modifiche si fanno in Stripe)',
        'videos.manage' => 'Video e ruoli ammessi',
        'attendees.export' => 'Export dei partecipanti per il check-in',
        'system.manage' => 'Utenti dello staff, ruoli, chiavi del partner, registro audit, eventi Stripe',
    ];

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => array_keys(self::PERMISSIONS),
            self::Commerciale => [
                'event.view', 'sales.view', 'discount_codes.manage', 'invite_codes.view', 'invite_codes.manage',
                'orders.view', 'orders.view_amounts', 'orders.manage', 'complimentary.view', 'refunds.manage',
                'accounting.manage', 'memberships.view', 'attendees.export',
            ],
            self::Organizzatore => [
                'event.view', 'event.manage', 'sales.view', 'sales.manage', 'discount_codes.manage',
                'invite_codes.view', 'orders.view', 'complimentary.view', 'complimentary.manage', 'videos.manage',
                'attendees.export',
            ],
        };
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }
}
