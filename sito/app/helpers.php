<?php

// Funzioni globali per le viste. Autore: Giulio Occhipinti.

if (! function_exists('localized')) {
    /** Testo di una colonna tradotta {"en": …, "it": …} nella lingua corrente; se manca, l'inglese. */
    function localized(?array $value): string
    {
        return $value[app()->getLocale()] ?? $value['en'] ?? '';
    }
}

if (! function_exists('money')) {
    /** Importo in centesimi nella valuta indicata (quella dell'evento o dell'ordine), es. € 695, US$ 276.50. */
    function money(int $cents, ?string $currency = null): string
    {
        $fmt = new NumberFormatter(app()->getLocale() === 'it' ? 'it_IT' : 'en_IE', NumberFormatter::CURRENCY);
        if ($cents % 100 === 0) {
            $fmt->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        }

        return $fmt->formatCurrency($cents / 100, strtoupper($currency ?? config('eventals.currency')));
    }
}
