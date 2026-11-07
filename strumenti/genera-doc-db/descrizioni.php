<?php

// Autore: Giulio Occhipinti.
// A cosa serve ogni tabella: una riga, letta da genera.php. Una tabella nuova senza riga qui compare nel documento
// con «(descrizione mancante)» e lo script lo segnala: aggiungila dopo ogni migrazione che crea una tabella.

return [
    // Dominio
    'events' => 'Evento (EAS8): nome tradotto, date, valuta, stato, finestra di vendita.',
    'event_days' => 'Giorni dell\'evento; servono ai pass giornalieri e al programma.',
    'program_sessions' => 'Sessioni del programma (workshop compresi); il nome evita lo scontro con `sessions` di Laravel.',
    'ticket_types' => 'Tipi di biglietto (pass intero, giornaliero, workshop, accompagnatore, espositore, staff) con canale e limiti per ordine.',
    'price_tiers' => 'Fasce di prezzo dell\'evento (early bird, regular, last minute), mai sovrapposte (trigger).',
    'ticket_prices' => 'Prezzo in centesimi per tipo × fascia × ruolo di prezzo (pubblico, membro); fascia nulla = prezzo base.',
    'capacity_pools' => 'Contatori dei posti: `held + confirmed <= capacity` garantito dal CHECK.',
    'ticket_type_pools' => 'Quali pool di capienza consuma ogni tipo di biglietto (molti a molti).',
    'capacity_holds' => 'Posti presi da un ordine su un pool: riserva con scadenza, attesa del pagamento, convertita o rilasciata.',
    'discount_codes' => 'Codici sconto calcolati dal server: tipo, valore, validità, ruoli ammessi, limiti di utilizzo.',
    'discount_code_ticket_types' => 'Tipi di biglietto a cui si applica un codice sconto (vuota = tutti).',
    'discount_code_redemptions' => 'Utilizzi dei codici per ordine; quelli non rilasciati contano nei limiti.',
    'orders' => 'Ordini: stato, ruolo e fascia fissati alla riserva, importi in centesimi, sessione e PaymentIntent Stripe.',
    'order_items' => 'Righe d\'ordine con prezzo di listino, sconto unitario, totale e nominativi (JSON).',
    'member_price_claims' => 'Prenotazione del prezzo membro: al massimo una attiva per membro, evento e tipo (indice unico).',
    'tickets' => 'Biglietti nominativi: uno per partecipante, stato, versione del QR, correzioni del nome (trigger).',
    'companion_claims' => 'Accompagnatore legato al pass: al massimo uno per pass (fase 2).',
    'refunds' => 'Rimborsi Stripe per ordine: importo, causa, origine (auto, staff, dashboard), stato, chiave di idempotenza.',
    'disputes' => 'Contestazioni dei pagamenti (chargeback) lette dai webhook `charge.dispute.*`.',
    'stripe_events' => 'Eventi Stripe ricevuti (id evento = chiave): idempotenza dei webhook e stato dell\'elaborazione.',
    'membership_plans' => 'Piano della membership: prezzo mostrato e Price ID Stripe ricorrente.',
    'memberships' => 'Copia locale della subscription Stripe del membro (stato, periodo, annullamento).',
    'consents' => 'Consensi registrati (newsletter, condizioni), solo inserimento (trigger).',
    'audit_log' => 'Registro audit delle azioni dello staff e dei job, solo inserimento (trigger).',
    'login_codes' => 'Codici di accesso dei clienti: HMAC del codice, scadenza 10 minuti, tentativi.',
    'sent_messages' => 'Email transazionali inviate, con chiave unica per non mandare due volte la stessa.',
    'users' => 'Clienti e staff; lo staff ha password argon2id e MFA, i clienti entrano con il codice via email.',
    // spatie/laravel-permission
    'permissions' => 'Permessi dello staff (spatie/laravel-permission), creati dal seeder da `App\Enums\StaffRole`.',
    'roles' => 'Ruoli dello staff: admin, commerciale, organizzatore.',
    'role_has_permissions' => 'Permessi di ogni ruolo.',
    'model_has_roles' => 'Ruoli assegnati agli utenti dello staff.',
    'model_has_permissions' => 'Permessi diretti a un utente (non usati: si passa dai ruoli).',
    // Laravel
    'sessions' => 'Sessioni HTTP di Laravel (driver database).',
    'password_reset_tokens' => 'Token di reimpostazione della password dello staff (Laravel).',
    'cache' => 'Cache di Laravel (driver database), anche per il RateLimiter.',
    'cache_locks' => 'Lock della cache (`withoutOverlapping` dello scheduler).',
    'jobs' => 'Coda dei job (driver database), vuotata dal cron ogni minuto.',
    'job_batches' => 'Batch di job di Laravel (non usati per ora).',
    'failed_jobs' => 'Job falliti dopo tutti i tentativi: da controllare (`php artisan queue:failed`).',
    'migrations' => 'Migrazioni già eseguite (Laravel).',
];
