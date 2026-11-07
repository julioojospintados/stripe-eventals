<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Lingua del sito: scelta nel selettore, poi quella dell'account, poi quella del browser; inglese predefinito. */
class SetLocale
{
    public const LOCALES = ['en', 'it'];

    public function handle(Request $request, Closure $next)
    {
        $locale = $request->session()->get('locale')
            ?? $request->user()->locale
            ?? $request->getPreferredLanguage(self::LOCALES);

        app()->setLocale(in_array($locale, self::LOCALES, true) ? $locale : 'en');

        return $next($request);
    }
}
