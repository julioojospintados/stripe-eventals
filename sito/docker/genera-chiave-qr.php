<?php

// Primo avvio con Docker: se nel .env manca la chiave Ed25519 dei QR, ne genera una solo per lo sviluppo locale.
// Autore: Giulio Occhipinti.

$file = __DIR__.'/../.env';
$env = file_get_contents($file);

if (preg_match('/^QR_SIGNING_PRIVATE_KEY=\S+/m', $env)) {
    exit(0);
}

$key = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
$line = 'QR_SIGNING_PRIVATE_KEY='.$key;

$env = preg_match('/^QR_SIGNING_PRIVATE_KEY=.*$/m', $env)
    ? preg_replace('/^QR_SIGNING_PRIVATE_KEY=.*$/m', $line, $env)
    : rtrim($env).PHP_EOL.$line.PHP_EOL;

file_put_contents($file, $env);
echo "Chiave QR di sviluppo generata nel .env\n";
