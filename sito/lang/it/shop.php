<?php

// Errori di vendita (App\Services\ShopException).
return [
    'not_available' => 'Questo biglietto non è in vendita adesso.',
    'sold_out' => 'Spiacenti, i posti sono esauriti.',
    'hold_too_short' => 'La riserva è troppo breve.',
    'too_many_for_type' => 'Troppi biglietti di questo tipo in un solo ordine.',
    'too_many_for_pool' => 'Troppi posti per questa sessione in un solo ordine.',
    'too_many_workshops' => 'Puoi prenotare al massimo 2 workshop per ordine.',
    'workshop_needs_pass' => 'I workshop si comprano solo insieme a un pass intero, o dopo.',
    'workshop_overlap' => 'Due dei workshop scelti sono alla stessa ora.',
    'member_price_limit' => 'Hai già un biglietto di questo tipo a prezzo membro: riprova.',
    'pending_order_exists' => 'Hai già un ordine in corso: riprova.',
    'discount_invalid' => 'Il codice sconto non è valido.',
    'discount_exhausted' => 'Il codice sconto è esaurito.',
    'discount_user_limit' => 'Hai già usato questo codice sconto.',
    'order_not_pending' => 'Questo ordine non si può più pagare.',
    'session_mismatch' => 'Il pagamento non appartiene a questo ordine.',
    'already_member' => 'Sei già membro.',
];
