# ADR 0011: Sistema agnostico, più eventi e un organizzatore per installazione

Autore: Giulio Occhipinti. Stato: proposta, da confermare. Data: 12/11/2026. Fonte: richiesta di Giulio del
12/11/2026 (riusare il sistema per altri eventi e altri organizzatori), audit del codice di `sito/`.

## Contesto

Lo schema era già per evento (`event_id` su tipi, fasce, pool, ordini, biglietti, sale, tracce, sessioni), ma il
codice dava per scontato l'EAS8: fuso `Europe/Lisbon` scritto in 30 punti del pannello, `EUR` fisso negli importi,
nome «EAS8» in pannello, email e pagine, comandi `eas:`, default `Europe/Lisbon`/`eur` nelle colonne, l'evento
pubblico preso come «l'ultimo per data», speaker ed export su tutti gli eventi insieme.

## Decisione

1. **Un'installazione = un organizzatore, con uno o più eventi.** Niente multi-tenant adesso.
2. **Impostazioni dell'installazione** in `config/eventals.php`, lette dal `.env` (`EVENTALS_*`): nome, contatto e logo
   dell'organizzatore, email in più per gli avvisi, lingue e lingua predefinita, valuta e fuso proposti (usati anche
   per membership, registro audit e scheduler), evento delle pagine pubbliche. `QR_PREFIX` in `config/tickets.php`.
3. **Impostazioni per evento** nella tabella `events`, già presenti: titolo (il nome), fuso, valuta, date, luogo,
   finestra di vendita. Il pannello mostra e scrive le ore nel fuso dell'evento del record (`Event::timezoneOf()`), gli
   importi nella valuta del record (`money()` nelle viste, `->money(fn ($record) => $record->currency)` nel pannello).
   Lingue e dominio per evento non servono oggi: le lingue sono quelle dei file `lang/`, il dominio è uno per
   installazione.
4. **Evento pubblico** con `Event::current()`: `EVENTALS_EVENT` se impostato, altrimenti il prossimo pubblicato non
   ancora finito, altrimenti l'ultimo pubblicato. Le pagine pubbliche lavorano su quello; area personale, pannello,
   export e riconciliazione su tutti, con filtro o colonna «Event».
5. **Nessun mescolamento** (migrazione `2026_11_12_090000_make_events_agnostic`): fuso e valuta senza default nello
   schema; trigger di difesa (come ADR 0002) su prezzi (valuta e fascia dell'evento del tipo), pool e codici sconto
   collegati a un tipo, valuta dell'ordine, valuta dell'evento bloccata dopo il primo prezzo o ordine. I model mettono
   la valuta giusta da soli; un codice sconto a importo fisso vale solo nella sua valuta.
6. **Comandi** `eventals:expire-orders`, `eventals:reconcile`, `eventals:create-admin`, `eventals:resend-ticket`; gli
   `eas:*` restano come alias deprecati (`$aliases`) per cron e script già scritti.
7. **EAS8 come dati**: `Eas8Seeder` resta il primo evento dimostrativo; `DemoEventSeeder` crea un evento generico
   (fuso e valuta a scelta, bozza di default). `DatabaseSeeder` di sviluppo li lancia entrambi.
8. **Struttura del codice invariata.** Spostare servizi e model in moduli cambierebbe namespace in tutto il codice e
   nei test senza cambiare comportamento; la mappa dei moduli qui sotto basta per orientarsi e per un'estrazione futura.

| Modulo | File |
|---|---|
| Eventi e impostazioni | `Models/Event.php`, `EventDay.php`, `config/eventals.php` |
| Prezzi e sconti | `Services/PriceResolver.php`, `Models/PriceTier.php`, `TicketPrice.php`, `DiscountCode*.php`, `MemberPriceClaim.php` |
| Capienza | `Services/CapacityService.php`, `Models/CapacityPool.php`, `CapacityHold.php` |
| Ordini e pagamenti | `Services/CheckoutService.php`, `PaymentService.php`, `StripeGateway.php`, `DisputeService.php`, `Jobs/ProcessStripeEvent.php`, `RefundOrderFully.php`, `Console/Commands/ExpireOrders.php`, `ReconcileStripe.php` |
| Biglietti | `Services/TicketService.php`, `ComplimentaryService.php`, `Models/Ticket*.php`, `Mail/TicketsMail.php` |
| Membership | `Services/MembershipService.php`, `Models/Membership*.php` |
| Programma | `Services/ProgramService.php`, `SpeakerPhoto.php`, `Models/ProgramSession.php`, `SessionPart.php`, `Room.php`, `Track.php`, `Speaker.php`, `Filament/Imports/*` |

## Verso il multi-tenant (non fatto)

Cosa servirebbe per ospitare più organizzatori in una sola installazione (SaaS):

- **Tabella `organizers`** e `organizer_id` su `events`, `membership_plans`, `users` dello staff, `discount_codes` senza
  evento, `consents`, `audit_log`; le tabelle figlie di un evento lo ereditano già da `event_id`.
- **Isolamento**: scope globale Eloquent sull'organizzatore corrente (dal dominio o dal pannello), Policy che
  confrontano l'organizzatore, indici unici composti (`organizer_id`, `slug`/`code`), export, code e file privati per
  organizzatore; il pannello Filament ha già i *tenant* (`->tenant(Organizer::class)`).
- **Pagamenti**: un account Stripe Connect per organizzatore (`stripe_account_id`), Checkout e rimborsi con
  l'header `Stripe-Account`, webhook Connect con l'account nell'evento, commissione della piattaforma
  (`application_fee_amount`), fatture e IVA a carico dell'organizzatore. Da far valutare alla catena legale e fiscale.
- **Clienti**: decidere se un acquirente è unico o per organizzatore (oggi `users.email` è unico); membership per
  organizzatore.
- **Configurazione**: le voci di `config/eventals.php` diventano colonne di `organizers`.

Già pronto: tutto per evento (`event_id` ovunque, fuso e valuta per evento, trigger anti-mescolamento), nessun valore
di un cliente nel codice, comandi e seeder neutri, `StripeGateway` come unico punto verso Stripe.

**Estrarre un pacchetto Composer** (dopo, se servirà a più installazioni): spostare i moduli della tabella sotto
`src/` con namespace proprio, un `EventalsServiceProvider` che registra config, migrazioni (`loadMigrationsFrom`),
comandi, viste e traduzioni pubblicabili; il pannello Filament come plugin (`Filament\Contracts\Plugin`); l'app resta
uno scheletro con `.env`, viste personalizzate e seeder dell'organizzatore. Prima serve una versione stabile dello
schema: le migrazioni del pacchetto non si possono più riscrivere.

## Alternative

- Multi-tenant subito: costa isolamento, Connect e decisioni fiscali che nessun cliente chiede ora.
- Un'installazione per evento: duplica membership, clienti e staff per lo stesso organizzatore.
- Moduli o pacchetto adesso: molti file spostati, nessun comportamento nuovo.

## Conseguenze

- Per un organizzatore nuovo basta il blocco `EVENTALS_*` del `.env` e gli eventi dal pannello (`sito/README.md`).
- Il QR ha il prefisso `QR_PREFIX` (predefinito `EVT1`, `EAS1` per l'EAS8): va concordato con il partner del check-in.
- Le pagine pubbliche mostrano un evento alla volta; più eventi pubblici contemporanei richiederebbero URL con lo slug.
- Selettore della lingua del layout ancora con EN/IT scritti nella vista; la colonna `price_eur` dei fogli resta
  accettata come `price`.
- Ruoli e permessi valgono su tutti gli eventi dell'installazione.
