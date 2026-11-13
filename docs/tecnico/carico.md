# Prova di carico dell'apertura vendite

Autore: Giulio Occhipinti. Misure del 12/11/2026 con [`strumenti/carico/`](../../strumenti/carico/README.md).

## Cosa simula

500 utenti (40% membri, 50% chiede anche un workshop, 10% prova due volte in parallelo come con due schede) che nello
stesso istante riservano e pagano gli ultimi **100 posti del pass** e **20 di un workshop**. Ogni processo PHP fa
riserva → Checkout → conferma del pagamento con i Service veri su MariaDB; Stripe è finto. Il pass consuma i tre pool
giornalieri, quindi ogni riserva blocca 3-4 righe di `capacity_pools` (in ordine di id).

Macchina: 4 core, 15 GB, PHP 8.4 CLI e MariaDB 10.11 sulla stessa macchina, `innodb_buffer_pool_size` 128 MB,
`innodb_lock_wait_timeout` 50 s, `max_connections` 151.

## Risultati

Tutti i controlli passano in ogni giro: nessun overselling, contatori dei pool uguali alle riserve, un solo prezzo
membro attivo per persona, nessun biglietto membro doppio, nessun deadlock né lock wait.

| Processi in parallelo | Durata | Riserva p50 / p95 / max | Pagamento p50 / p95 | Esiti |
|---|---|---|---|---|
| 10 | 3,8 s | 58 / 90 / 190 ms | 78 / 125 ms | 100 riservati e pagati, 450 esauriti |
| 25 | 3,9 s | 141 / 272 / 461 ms | 194 / 308 ms | uguale |
| 50 | 4,3 s | 273 / 611 / 877 ms | 396 / 627 ms | uguale |
| 100 | 4,9 s | 678 / 1.329 / 1.627 ms | 808 / 954 ms | uguale |
| 50, posti in abbondanza (1.000), 30% doppi | 12,3 s | 440 / 687 / 860 ms | 437 / 655 ms | 650 riservati e pagati |

Checkout (senza Stripe vero): p50 8 ms. I 550 tentativi passano in circa 4 secondi qualunque sia il numero di
processi: **circa 130-145 riserve al secondo**, perché le riserve dello stesso pass si mettono in fila sui lock delle
righe dei pool (6-8 ms ciascuna). Più processi non aumentano il ritmo, allungano solo l'attesa.

Con i posti in abbondanza i «doppi» comprano due volte (secondo ordine dopo il primo pagato): il secondo biglietto è a
prezzo pubblico, il prezzo membro resta uno.

## Limiti della prova

- Stripe finto: in produzione `startCheckout` aspetta Stripe (centinaia di ms) ma **fuori** dalla transazione, quindi
  non tiene lock; tiene però occupato un processo PHP-FPM.
- Niente HTTP: mancano middleware, sessioni nel database e rendering (qualche decina di ms per richiesta). Una prova
  HTTP con `php artisan serve` chiamerebbe lo Stripe vero e non è stata fatta.
- PHP e MariaDB sulla stessa macchina con 4 core: su un hosting condiviso i numeri possono essere peggiori.
- Un tentativo esaurito blocca comunque i pool prima di fallire: dopo l'esaurimento continua a costare come una
  riserva. Con questi numeri non serve un controllo senza lock prima della transazione.

## Configurazione consigliata

| Cosa | Valore | Perché |
|---|---|---|
| PHP-FPM `pm.max_children` | 20-30 su 4 core (circa 5-8 per core), `pm = dynamic` | Oltre, la riserva non va più veloce (fila sui lock) e cresce solo l'attesa; ogni processo Laravel + Filament occupa qualche decina di MB [uncertain: misurare con `ps` sul server] |
| PHP-FPM `request_terminate_timeout` | 30 s | Una richiesta bloccata non tiene il processo per sempre |
| MariaDB `max_connections` | almeno `max_children` + worker della coda + 20 | Una connessione per processo, più cron, coda e pannello |
| `innodb_lock_wait_timeout` | 15-20 s (il default 50 va bene) | Con 100 processi l'attesa massima misurata è 1,6 s; `reserve()` ritenta 3 volte deadlock e lock wait (`DB::transaction(…, 3)`) |
| `innodb_buffer_pool_size` | almeno 256 MB | Le tabelle stanno in memoria |
| Indici | quelli che ci sono bastano | Le query calde (pool per id, riserve per ordine, ordine pending per utente, prezzo membro attivo) usano già indici unici o di chiave esterna |
| Coda | worker `critical` sempre attivo (`QUEUE_WORKER_MODE=daemon`) | I webhook dell'apertura arrivano in pochi secondi; le email dei biglietti escono con il limite SMTP |
