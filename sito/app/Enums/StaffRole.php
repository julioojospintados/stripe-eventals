<?php

namespace App\Enums;

/**
 * Ruoli dello staff (spatie/laravel-permission) e i loro permessi predefiniti, da docs/architettura.md §5.3.
 * Membro non è un ruolo: si deriva da memberships. Dopo una modifica: php artisan db:seed --class=RolesAndPermissionsSeeder
 * (crea i permessi nuovi e li dà ai ruoli come qui; non tocca le modifiche fatte dall'admin nel pannello).
 */
enum StaffRole: string
{
    case Admin = 'admin';
    case Commerciale = 'commerciale';
    case Organizzatore = 'organizzatore';

    /** Risorse con vedi, crea, modifica, elimina. Nessun ruolo elimina: nel pannello si spegne, non si cancella. */
    public const RESOURCES = [
        'event' => 'Evento, date e giorni',
        'ticket_types' => 'Tipi di biglietto',
        'price_tiers' => 'Fasce di prezzo',
        'ticket_prices' => 'Prezzi tipo × fascia × ruolo',
        'capacity_pools' => 'Capienze',
        'discount_codes' => 'Codici sconto',
        'staff' => 'Utenti dello staff',
        'rooms' => 'Sale',
        'tracks' => 'Tracce',
        'program_sessions' => 'Sessioni del programma, interventi e speaker per sessione',
        'speakers' => 'Speaker',
    ];

    public const VERBS = ['view' => 'vedi', 'create' => 'crea', 'update' => 'modifica', 'delete' => 'elimina'];

    /** Permessi che non sono «vedi, crea, modifica, elimina» di una risorsa. */
    public const ACTIONS = [
        'orders.view' => 'Ordini: vedi (senza importi)',
        'orders.view_amounts' => 'Ordini, pagamenti, rimborsi: vedi gli importi',
        'orders.refund' => 'Ordini: rimborsa',
        'orders.review' => 'Ordini da rivedere: decidi (emetti i biglietti o rimborsa)',
        'orders.export' => 'Export contabile degli ordini',
        'complimentary.view' => 'Omaggi: vedi',
        'complimentary.issue' => 'Omaggi: emetti e revoca',
        'tickets.view' => 'Biglietti: vedi',
        'tickets.correct_attendee' => 'Biglietti: correggi un refuso nel nome',
        'tickets.resend' => 'Biglietti: reinvia per email',
        'attendees.export' => 'Export dei partecipanti per il check-in',
        'consents.export' => 'Export degli iscritti alla newsletter con consenso',
        'memberships.view' => 'Membership: vedi (si modificano in Stripe)',
        'membership_plans.view' => 'Prezzo della membership: vedi',
        'membership_plans.update' => 'Prezzo della membership e Price ID Stripe: modifica',
        'disputes.view' => 'Contestazioni dei pagamenti: vedi',
        'reconciliation.run' => 'Riconciliazione con Stripe: avvia',
        'roles.view' => 'Ruoli e permessi: vedi',
        'roles.update' => 'Ruoli e permessi: modifica',
        'audit_log.view' => 'Registro audit: vedi',
        'invite_codes.view' => 'Codici d\'invito per espositori (fase 2): vedi',
        'invite_codes.manage' => 'Codici d\'invito per espositori (fase 2): crea e modifica',
        'videos.manage' => 'Video e ruoli ammessi (fase 2)',
        'program.import' => 'Programma: import da CSV di sale, speaker, sessioni e interventi',
    ];

    /** @return array<string, string> tutti i permessi, con il significato */
    public static function allPermissions(): array
    {
        $all = [];
        foreach (self::RESOURCES as $resource => $label) {
            foreach (self::VERBS as $verb => $word) {
                $all["$resource.$verb"] = "$label: $word";
            }
        }

        return $all + self::ACTIONS;
    }

    /** @return list<string> permessi predefiniti del ruolo */
    public function permissions(): array
    {
        $sales = ['ticket_types', 'price_tiers', 'ticket_prices', 'capacity_pools'];
        $program = ['rooms', 'tracks', 'program_sessions', 'speakers'];
        $read = fn (array $resources) => array_map(fn ($r) => "$r.view", $resources);
        $write = fn (array $resources) => array_merge(...array_map(fn ($r) => ["$r.view", "$r.create", "$r.update"], $resources));

        return match ($this) {
            self::Admin => array_values(array_filter(array_keys(self::allPermissions()), fn ($p) => ! str_ends_with($p, '.delete'))),
            self::Commerciale => [
                ...$read(['event', ...$sales]), ...$write(['discount_codes']),
                'orders.view', 'orders.view_amounts', 'orders.refund', 'orders.review', 'orders.export',
                'complimentary.view', 'tickets.view', 'tickets.resend', 'attendees.export', 'consents.export',
                'memberships.view', 'membership_plans.view', 'disputes.view', 'reconciliation.run',
                'invite_codes.view', 'invite_codes.manage',
            ],
            self::Organizzatore => [
                ...$write(['event', ...$sales, 'discount_codes', ...$program]), 'program.import',
                'orders.view', 'complimentary.view', 'complimentary.issue', 'tickets.view', 'attendees.export',
                'invite_codes.view', 'videos.manage',
            ],
        };
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }
}
