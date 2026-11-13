<?php

// Biglietti e QR firmato (docs/architettura.md §11). La chiave privata sta solo nel .env.

return [

    'qr' => [
        // Prima parte del QR (<prefisso>.<dati>.<firma>): la legge il lettore del partner del check-in. EAS8: EAS1.
        'prefix' => env('QR_PREFIX', 'EVT1'),
        // Chiave privata Ed25519 (ext-sodium) in base64; key_id finisce nel QR per poter ruotare la chiave.
        'signing_private_key' => env('QR_SIGNING_PRIVATE_KEY'),
        'signing_key_id' => env('QR_SIGNING_KEY_ID', 'k1'),
        // Chiavi pubbliche delle rotazioni passate, solo per verificare i QR già inviati: "k1:base64,k2:base64".
        'previous_public_keys' => env('QR_PREVIOUS_PUBLIC_KEYS'),
    ],

];
