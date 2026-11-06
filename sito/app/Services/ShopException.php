<?php

namespace App\Services;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Regola di vendita non rispettata (posti esauriti, limite per ordine, codice esaurito...). Il messaggio è un codice
 * stabile (es. sold_out) usato dai test; il testo per l'utente è in lang/{en,it}/shop.php.
 */
class ShopException extends RuntimeException
{
    public function render(Request $request)
    {
        $text = __('shop.'.$this->getMessage());

        return $request->expectsJson()
            ? response()->json(['error' => $this->getMessage(), 'message' => $text], 422)
            : back()->withInput()->withErrors(['shop' => $text]);
    }
}
