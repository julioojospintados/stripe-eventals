<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Lingua del sito: scelta nel selettore, poi quella dell'account, poi quella del browser; poi eventals.locale. */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locales = config('eventals.locales');
        $locale = $request->session()->get('locale')
            ?? $request->user()->locale
            ?? $request->getPreferredLanguage($locales);

        app()->setLocale(in_array($locale, $locales, true) ? $locale : config('eventals.locale'));

        return $next($request);
    }
}
