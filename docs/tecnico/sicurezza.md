# Revisione di sicurezza

Autore: Giulio Occhipinti. Revisione del 12/11/2026 su `sito/` (Laravel 13, Filament 5, PHP 8.4, MariaDB 10.11).
Criteri: ruolo `architecture-security` e, per catch, fallback e job, `silent-failure-hunter`. Solo backend.

Gravità: 🔴 alta · 🟡 media · 🟢 bassa o risolta.

## Cosa è stato corretto

Ogni correzione ha un test (`tests/Feature/SecurityTest.php`, `tests/Feature/Qa/UncoveredRulesTest.php`).

| Gravità prima | Problema | Correzione |
|---|---|---|
| 🔴 | Con `STRIPE_WEBHOOK_SECRET` vuoto (dimenticato nel `.env`) la firma si calcola con chiave vuota: chiunque conosca l'id della propria sessione poteva mandare un `checkout.session.completed` falso e avere i biglietti senza pagare | `StripeWebhookController`: segreto vuoto → risposta 500 e `report()`, nessun evento salvato |
| 🟡 | Codice di accesso: 3 codici ogni 10 minuti per email e limiti solo per IP; con molti IP un attaccante arrivava a ~2.000 prove al giorno su 10⁶ codici | Tetto di 10 codici al giorno per email (`LoginCodeService::send()`): al massimo 50 prove al giorno |
| 🟡 | `SendTicketsEmail` e `GenerateCsvExport` senza `failed()`: biglietti mai partiti o export mai pronto senza che nessuno lo sappia | `failed()` con `StaffAlert` (log e email agli admin) |
| 🟡 | Rotte della membership senza limite: ogni richiesta chiama l'API di Stripe e un solo account poteva consumare il rate limit di Stripe di tutto il sito, anche durante l'apertura vendite | `throttle:10,1` su avvio, ritorno e portale |
| 🟢 | Formula injection nei CSV: mancavano tab e a capo iniziali (OWASP) | `CsvExports::safe()` |
| 🟢 | Header di sicurezza solo nella configurazione nginx: su un hosting condiviso con Apache nessuno | `public/.htaccess` con `mod_headers` (nosniff, SAMEORIGIN, Referrer-Policy, Permissions-Policy, HSTS solo su HTTPS) |

## Cosa è stato controllato e va bene

| Area | Esito |
|---|---|
| Codice di accesso clienti | 🟢 6 cifre da `random_int`, salvato solo come HMAC-SHA256 con `APP_KEY`, 10 minuti, una volta (`used_at`), 5 tentativi con lock sulla riga, confronto `hash_equals`, sessione rigenerata al login e invalidata al logout |
| Enumerazione email | 🟢 stessa risposta per email nuove, esistenti e dello staff; l'email parte dopo la risposta |
| Staff | 🟢 password argon2id (minimo 12 caratteri), MFA TOTP obbligatoria con codici di recupero, `AuthenticateSession`, login Filament limitato a 5 tentativi al minuto, lo staff non entra con il codice |
| Policy e IDOR | 🟢 area personale con query dalle relazioni dell'utente (ordini, biglietti, QR); pannello con un permesso per azione; export solo dalla cartella dello staff che li ha chiesti, nome file vincolato dalla rotta |
| QR | 🟢 firma Ed25519 con id della chiave, rotazione con chiavi pubbliche precedenti, `Cache-Control: no-store` |
| Webhook Stripe | 🟢 firma con tolleranza di 300 s (default di `stripe-php`), `livemode` controllato, `insertOrIgnore` sull'id evento (replay e reinvii), job con lock sulla riga e uscita se già elaborato, importo diverso → `needs_review` |
| CSRF | 🟢 escluso solo `stripe/webhook` |
| Rate limit | 🟢 carrello, checkout, login e verifica `throttle:10,1`; limiti per email e IP nel Service |
| Upload | 🟢 foto speaker JPEG/PNG, 5 MB, ricodificate con GD (niente EXIF), nome casuale; `photo_file` dell'import vincolato da regex (niente percorsi); CSV importati solo `csv`/`txt` e solo con `program.import` |
| Mass assignment | 🟢 tutti i 29 modelli con `#[Fillable]`; prezzi e importi calcolati nel Service, mai dal browser |
| Query raw | 🟢 solo espressioni fisse o con binding (`CapacityService`, widget, export consensi) |
| Link pubblici | 🟢 link degli speaker solo `http(s)` (regola `url` e filtro nella vista); URL fattura solo dal webhook firmato |
| Errori ingoiati | 🟢 i `catch` sono specifici (`ShopException`, `UniqueConstraintViolationException`, `InvalidRequestException` di Stripe seguito da una rilettura); `ExpireOrders` fa `report()` e riprova al minuto dopo; `ProcessStripeEvent` segna `failed` e rilancia |
| Segreti nei log | 🟢 nessun codice, password o chiave nei log; gli avvisi contengono id di ordini ed email dell'acquirente solo dove serve allo staff |
| Dipendenze | 🟢 `composer audit`: nessuna vulnerabilità nota al 12/11/2026 |

## Cosa resta

| Gravità | Rischio | Cosa fare |
|---|---|---|
| 🟡 | Dietro un proxy o un CDN (Cloudflare, bilanciatore) senza `trustProxies` tutti i clienti hanno lo stesso IP: i limiti per IP diventano un blocco per tutti | Se l'hosting ha un proxy davanti, configurare `$middleware->trustProxies(at: …)` in `bootstrap/app.php` con gli IP del proxy |
| 🟡 | Il tetto di 10 codici al giorno è anche un blocco: un attaccante può impedire per un giorno l'accesso a un'email nota | Accettato (lo staff può aiutare il cliente); da rivedere se succede |
| 🟡 | Nessuna Content-Security-Policy: Filament e Livewire usano script inline e `eval` di Alpine | Valutare una CSP solo per le pagine pubbliche, dopo un giro di prova |
| 🟢 | Staff appena creato prima della configurazione MFA: è autenticato e può aprire `/admin/exports/{file}` (fuori dal pannello), ma non ha file suoi | Nessuna azione; la MFA si configura al primo accesso |
| 🟢 | Foto molto grandi in pixel (decompression bomb) possono esaurire la memoria di GD; solo staff | Limite di 5 MB e `memory_limit` di PHP |
| 🟢 | `/language/{locale}` cambia la lingua con un GET (CSRF innocuo, valori ammessi fissi) | Nessuna |
| 🟢 | Lo staff riceve la stessa risposta di un cliente ma salta la scrittura del codice: differenza di tempi minima | Nessuna |
