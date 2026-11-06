<?php

// Biglietti e QR firmato (docs/architettura.md §11). La chiave privata sta solo nel .env.

return [

    'qr' => [
        // Chiave privata Ed25519 (ext-sodium) in base64; key_id finisce nel QR per poter ruotare la chiave.
        'signing_private_key' => env('QR_SIGNING_PRIVATE_KEY'),
        'signing_key_id' => env('QR_SIGNING_KEY_ID', 'k1'),
    ],

];
