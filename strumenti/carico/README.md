# Prova di carico dell'apertura vendite

Autore: Giulio Occhipinti. Versione 1.0 del 12/11/2026. Risultati e configurazione consigliata in
[docs/tecnico/carico.md](../../docs/tecnico/carico.md).

`carico.php` prepara un database usa e getta con l'evento dimostrativo, lascia pochi posti (pass e un workshop), crea
gli utenti (una parte membri, una parte che prova due volte in parallelo) e lancia processi PHP separati che, nello
stesso istante, riservano, aprono il Checkout e pagano con i Service veri (`CheckoutService`, `PaymentService`) su
MariaDB/MySQL. Stripe è finto (`Tests\Fakes\FakeStripe`): nessuna chiamata di rete. Alla fine controlla:

- nessun pool oltre la capienza e contatori uguali alla somma delle riserve;
- biglietti validi non oltre i posti;
- un solo prezzo membro attivo per persona e tipo, nessun biglietto membro doppio pagato;
- nessun deadlock, lock wait o errore non gestito;

e stampa p50, p95 e massimo dei tempi di riserva, checkout e pagamento. Esce con 1 se un controllo fallisce.

## Uso

Una volta, da root sul database (lo script lo svuota con `migrate:fresh`, quindi il nome deve finire con `_carico` o
`_test`):

```
mariadb -e "create database eas8_carico; grant all privileges on eas8_carico.* to 'eas8'@'localhost';"
```

Poi dalla radice del repository (serve `sito/vendor` con le dipendenze dev e il `.env` di `sito/` per host e utente):

```
php strumenti/carico/carico.php
php strumenti/carico/carico.php --users=500 --workers=50 --pass=100 --workshop=20 --members=40 --duplicates=10 --with-workshop=50
```

`--workers` sono i processi in parallelo, come i processi PHP-FPM; le percentuali sono sugli utenti. Mai sul
database di produzione.
