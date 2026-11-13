<?php

// Impostazioni dell'installazione: un'installazione = un organizzatore, con uno o più eventi (docs/tecnico/adr/0011).
// Quello che cambia da evento a evento (fuso, valuta, date, testi) sta nella tabella events, non qui.

return [

    'organizer' => [
        // Nome nel pannello, nei titoli delle pagine senza evento e nelle email (codice di accesso, avvisi).
        'name' => env('EVENTALS_ORGANIZER_NAME', env('APP_NAME', 'Eventals')),
        // Contatto mostrato nelle pagine pubbliche; vuoto = nessun contatto.
        'contact_email' => env('EVENTALS_CONTACT_EMAIL'),
        // Logo in public/, es. images/logo.png; vuoto = niente logo.
        'logo' => env('EVENTALS_LOGO'),
    ],

    // Indirizzo in più per gli avvisi allo staff (oltre agli admin del pannello); vuoto = solo gli admin.
    'alerts_email' => env('EVENTALS_ALERTS_EMAIL'),

    // Lingue del sito (servono i file in lang/) e lingua predefinita.
    'locales' => array_values(array_filter(explode(',', (string) env('EVENTALS_LOCALES', 'en,it')))),
    'locale' => env('EVENTALS_LOCALE', 'en'),

    // Valori proposti per un evento nuovo e per ciò che non appartiene a un evento (membership, registro, scheduler).
    'currency' => strtolower((string) env('EVENTALS_CURRENCY', 'eur')),
    'timezone' => env('EVENTALS_TIMEZONE', 'UTC'),

    // Slug dell'evento delle pagine pubbliche; vuoto = il prossimo evento pubblicato non ancora finito (Event::current).
    'event' => env('EVENTALS_EVENT'),

];
