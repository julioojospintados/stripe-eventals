# Regole di business: dove sono garantite e quale test le prova

Autore: Giulio Occhipinti. Aggiornato al 12/11/2026 sul codice di `sito/` (361 test Pest su MariaDB).

Percorsi relativi a `sito/`; i test a `sito/tests/`. Vincoli e trigger hanno il nome che si trova in
[database.md](database.md); le righe indicate sono quelle del 07/11/2026 e si spostano con le modifiche: il nome del
metodo resta il riferimento. **Livello**: DB = vincolo (CHECK, UNIQUE, FK), TRG = trigger, SRV = servizio, POL =
Policy o controllo di accesso, JOB = job o comando.

Una regola garantita solo da un trigger vale, sugli hosting senza trigger (`DB_TRIGGERS_OPTIONAL=true`), solo per
il codice: per questo le transizioni sono ripetute nei servizi.

## Capienza e riserve

| Regola | Dove | Test |
|---|---|---|
| Mai più posti presi della capienza | DB `capacity_pools_no_oversell` (`held + confirmed <= capacity`); SRV `Services/CapacityService.php` `hold()` r. 32 e `confirmDirect()` r. 49 (UPDATE condizionale) | `Feature/Database/SalesSchemaTest.php` › capienza › «rifiuta un aumento cieco dei venduti oltre la capienza», «rifiuta la capienza ridotta sotto i posti presi»; `Concurrency/ParallelTest.php` › «ultimo posto: due carrelli sul workshop da 1 posto…»; `Feature/Shop/CheckoutTest.php` › «rifiuta posti esauriti» |
| Pool bloccati sempre in ordine di id (niente deadlock) | SRV `CapacityService::lockPools()` r. 133 | indiretto: `Concurrency/ParallelTest.php` |
| Riserva di almeno 30 minuti; sessione 31, riserva 36 | SRV `CapacityService::hold()` r. 25 (`MIN_HOLD_MINUTES`); `CheckoutService` `SESSION_MINUTES`, `HOLD_EXTRA_MINUTES` | `Feature/Shop/CheckoutTest.php` › «riserva i posti per 36 minuti con la sessione da 31», «rifiuta una riserva sotto i 30 minuti» |
| Riserva attiva con scadenza, rilasciata con data | DB `capacity_holds_active_expires`, `capacity_holds_released_dated` | `SalesSchemaTest.php` › «rifiuta una riserva attiva senza scadenza», «rifiuta una riserva rilasciata senza data» |
| Una riserva per ordine e pool | DB `capacity_holds_order_id_pool_id_unique` | `SalesSchemaTest.php` › «rifiuta una doppia riserva per lo stesso ordine e pool» |
| I posti si liberano solo dopo che Stripe conferma la scadenza | JOB `Console/Commands/ExpireOrders.php` r. 24-39 (expire su Stripe, poi `applySession`) | `Feature/Shop/PaymentTest.php` › eventals:expire-orders › «non tocca le riserve non ancora scadute», «sessione ancora aperta: la fa scadere su Stripe e poi rilascia», «sessione pagata nel frattempo: conferma invece di rilasciare», «ordine mai arrivato a Stripe: chiuso senza chiamate» |
| Riserva allungata se l'acquirente aspetta prima di pagare | SRV `CheckoutService::extendIfNeeded()` r. 192 | `CheckoutTest.php` › «allunga la riserva se l'acquirente ha aspettato prima di pagare» |
| Allungamento rifiutato se intanto il membro non lo è più | SRV `CheckoutService::extendIfNeeded()` r. 198 | `Feature/Qa/UncoveredRulesTest.php` › «non allunga la riserva a prezzo membro se intanto la membership finisce» |
| Limite per ordine su tipo e pool | SRV `CheckoutService::checkLimits()` r. 243, 249 | `CheckoutTest.php` › «applica il limite per ordine sul pool», «applica il limite per tipo» |

## Ordini

| Regola | Dove | Test |
|---|---|---|
| Un solo ordine `pending` per persona | DB colonna generata `orders.pending_user_id` + `orders_one_pending_per_user`; SRV `CheckoutService::closePreviousPending()` r. 174 | `Feature/Database/OrdersSchemaTest.php` › ordini › «rifiuta un secondo ordine pending della stessa persona»; `CheckoutTest.php` › «con un secondo ordine fa scadere la sessione Stripe del primo e lo chiude», «se il primo ordine intanto è stato pagato, lo conferma invece di chiuderlo» |
| Totale = subtotale − sconto, importi non negativi | DB `orders_amounts_valid` | `OrdersSchemaTest.php` › «rifiuta un totale diverso da subtotale meno sconto» |
| Riga: prezzo − sconto unitario, quantità 1-50 | DB `order_items_amounts_valid` | `Feature/Qa/UncoveredRulesTest.php` › «order_items_amounts_valid rifiuta importi incoerenti» (3 casi) |
| Nominativi in numero uguale alla quantità | DB `order_items_attendees_match` | `OrdersSchemaTest.php` › «rifiuta nominativi in numero diverso dalla quantità» |
| Ordine `paid` sempre con `paid_at` | DB `orders_paid_has_date` | `OrdersSchemaTest.php` › «rifiuta un ordine paid senza data» |
| Solo le transizioni di stato ammesse | TRG `orders_guard`; SRV `Enums/OrderStatus.php` `canTransitionTo()`, `PaymentService::transition()` r. 250 | `OrdersSchemaTest.php` › «rifiuta la transizione pending → refunded», «non annulla senza rimborso un ordine pagato con Stripe»; `PaymentTest.php` › «chiude solo ordini pending o processing, anche senza trigger» |
| Ruolo, fascia, importi fissati alla riserva e immutabili | TRG `orders_guard` (`order_snapshot_immutable`); SRV `CheckoutService::reserve()` r. 47-99 | `OrdersSchemaTest.php` › «non cambia fascia né importi dopo la riserva»; `Feature/Shop/PricingTest.php` › «fissa ruolo, fascia e importi in centesimi alla riserva», «non cambia il prezzo se la fascia finisce durante il pagamento» |
| La sessione Stripe deve essere quella dell'ordine | SRV `PaymentService::checkSession()` r. 241 | `PaymentTest.php` › «rifiuta una sessione Stripe diversa da quella dell'ordine»; `Feature/Account/AccountTest.php` › «una session_id che non è dell'ordine non cambia nulla» |
| Ordine a costo zero confermato senza Stripe | SRV `CheckoutService::startCheckout()` r. 121 | `PricingTest.php` › «conferma senza Stripe un ordine a costo zero» |
| Importo pagato diverso dal totale: niente biglietti, decide lo staff | SRV `PaymentService::markPaid()` r. 68-77, `approveReview()`, `refundReview()`; POL `OrderPolicy::review` | `Feature/Qa/MoneyEdgeCasesTest.php` › «importo Stripe diverso dal totale: ordine da rivedere, niente biglietti, avviso allo staff»; `Feature/Shop/StripeClosuresTest.php` › «importo diverso: nessun biglietto, posti tenuti, avviso; lo staff emette i biglietti», «importo diverso: lo staff rimborsa…», «le azioni sugli ordini da rivedere non compaiono all'organizzatore» |

## Prezzi, membro e codici sconto

| Regola | Dove | Test |
|---|---|---|
| Prezzo per tipo × fascia × ruolo, dal database | SRV `Services/PriceResolver.php`; `CheckoutService::priceLine()` r. 285 | `PricingTest.php` › PriceResolver › «dà il prezzo giusto per fascia e ruolo» |
| Fasce attive mai sovrapposte | TRG `price_tiers_no_overlap_insert`, `price_tiers_no_overlap_update`; DB `price_tiers_window` | `SalesSchemaTest.php` › fasce di prezzo (5 test); `Feature/Panel/ActionsTest.php` › «mostra un errore leggibile per fasce attive sovrapposte» |
| Fascia spenta ignorata, si usa il prezzo base | SRV `PriceResolver::activeTier()` | `PricingTest.php` › «ignora la fascia spenta (last minute) e usa il prezzo base» |
| Un prezzo per tipo, fascia (o base) e ruolo | DB colonna generata `ticket_prices.tier_key` + `ticket_prices_one_per_tier_role` | `SalesSchemaTest.php` › prezzi (3 test) |
| Membro solo se la membership copre tutta la sessione di pagamento | SRV `CheckoutService::reserve()` r. 53, `Models/User.php` `isMemberUntil()` r. 67 | `PricingTest.php` › «è membro solo se la membership copre tutta la sessione di pagamento» |
| Prezzo membro una volta per membro, evento e tipo, anche su più ordini | DB colonna generata `member_price_claims.active_key` + `member_price_claims_one_active`, `member_price_claims_kind_shape`; SRV `CheckoutService::claimMemberPrice()` r. 103 | `OrdersSchemaTest.php` › prezzo membro (3 test); `PricingTest.php` › «dà il prezzo membro una volta per evento e tipo, anche su due ordini»; `ParallelTest.php` › «prezzo membro su due ordini in parallelo…» |
| Prezzo membro solo sul biglietto intestato al membro, quantità 1 | DB `order_items_member_qty_one`; SRV `CheckoutService::priceLine()` r. 292 | `OrdersSchemaTest.php` › «rifiuta una riga a prezzo membro di quantità 2»; `PricingTest.php` › «dà il prezzo membro solo al biglietto intestato al membro che compra» |
| Sulla riga del membro il migliore tra prezzo membro e codice, mai entrambi salvo codice combinabile | SRV `CheckoutService::priceLine()` r. 294-299 | `PricingTest.php` › «sulla riga del membro sceglie il migliore…», «somma codice e prezzo membro solo se il codice è combinabile» |
| Pagamento tardivo: prezzo membro ripreso, se non si può il biglietto vale e lo staff è avvisato | SRV `PaymentService::retakeClaims()` r. 277 | `Feature/Qa/LatePaymentTest.php` › «prezzo membro ripreso dopo un pagamento tardivo…» |
| Sconto calcolato riga per riga sul prezzo pubblico, nessun Coupon Stripe | SRV `PriceResolver::unitDiscount()`; `CheckoutService::startCheckout()` (`allow_promotion_codes: false`) | `PricingTest.php` › «sconta riga per riga il prezzo pubblico» |
| Codice valido: attivo, evento giusto, nelle date | SRV `CheckoutService::findDiscount()` r. 329-340 | `PricingTest.php` › «rifiuta un codice scaduto o di un altro evento» |
| Codice limitato a certi ruoli (`eligible_roles`) | SRV `CheckoutService::findDiscount()` r. 337 | `Feature/Qa/UncoveredRulesTest.php` › «un codice solo per il pubblico non vale per un membro» |
| Utilizzi massimi del codice, anche in parallelo | DB `discount_codes_redemptions_valid`; SRV `CheckoutService::redeem()` r. 353 (UPDATE condizionale) | `MoneyEdgeCasesTest.php` › «codice esaurito: il secondo carrello è rifiutato…»; `PricingTest.php` › «conta l'utilizzo alla riserva e lo restituisce alla scadenza» |
| Utilizzo restituito se l'ordine non si paga; ripreso al pagamento tardivo | SRV `PaymentService::releaseDiscount()` r. 264, `retakeClaims()` r. 287 | `PricingTest.php` › «conta l'utilizzo…»; `MoneyEdgeCasesTest.php` › «pagamento tardivo con codice intanto esaurito…» |
| Limite per persona | SRV `CheckoutService::findDiscount()` r. 344 | `PricingTest.php` › «rispetta il limite per persona» |
| Un solo utilizzo per ordine | DB `discount_code_redemptions_order_id_unique` | `Feature/Qa/UncoveredRulesTest.php` › «un solo utilizzo di codice per ordine…» |
| Forma del codice (maiuscolo, 3-40 caratteri, tipo e valore coerenti, date) | DB `discount_codes_code_format`, `discount_codes_shape`, `discount_codes_window` | `Feature/Database/OtherTablesSchemaTest.php` › «rifiuta un codice sconto malformato», «salva il codice sconto in maiuscolo» |

## Catalogo e carrello

| Regola | Dove | Test |
|---|---|---|
| Forma dei tipi (giornaliero con giorno, workshop con sessione, omaggio solo dal pannello, accompagnatore con limite) | DB `ticket_types_kind_shape`, `ticket_types_channel_shape` | `SalesSchemaTest.php` › tipi di biglietto (3 test) |
| Si vendono solo tipi attivi, del canale pubblico, nella finestra di vendita | SRV `CheckoutService::sellableTypes()` r. 209-228 | `CheckoutTest.php` › «non vende tipi spenti o di un altro canale» |
| Evento pubblicato e vendite aperte (`sales_open_at`, `sales_close_at`) | SRV `CheckoutService::sellableTypes()` r. 220 | `Feature/Qa/UncoveredRulesTest.php` › «non vende se l'evento non è pubblicato o le vendite sono chiuse» (bozza, chiuse, non ancora aperte) |
| Workshop solo con pass intero, al massimo 2, mai sovrapposti | SRV `CheckoutService::checkLimits()` r. 253-277 | `CheckoutTest.php` › workshop (4 test) |
| Solo carta (Apple Pay e Google Pay come carta) | SRV `CheckoutService::startCheckout()` r. 155, `MembershipService::startCheckout()` r. 31 | `CheckoutTest.php` › «crea la Checkout Session con price_data, solo carta, metadata e Idempotency-Key»; `Feature/Shop/MembershipTest.php` › «apre il Checkout in abbonamento…» |
| Date coerenti (evento, vendite, sessioni del programma) | DB `events_dates_valid`, `events_sales_window`, `program_sessions_times_valid`, `ticket_types_sales_window` | `Feature/Qa/UncoveredRulesTest.php` › «i CHECK rifiutano date al contrario su eventi e tipi di biglietto»; `ProgramRulesTest.php` › «rifiuta una fine non dopo l'inizio…» |

## Pagamenti, webhook, rimborsi

| Regola | Dove | Test |
|---|---|---|
| Webhook con firma valida e modalità giusta, altrimenti 400 | SRV `Http/Controllers/StripeWebhookController.php` r. 22-32 | `Feature/Shop/WebhookTest.php` › «rifiuta una firma sbagliata senza scrivere nulla», «rifiuta un evento live su un ambiente di test» |
| Ogni evento Stripe salvato e messo in coda una volta sola | DB PK `stripe_events.id`; SRV `StripeWebhookController` r. 34-48 (`insertOrIgnore` + job nella stessa transazione) | `SalesSchemaTest.php` › eventi Stripe › «salva una volta sola un evento ripetuto»; `WebhookTest.php` › «salva l'evento e risponde subito…» |
| Evento elaborato una volta sola | JOB `Jobs/ProcessStripeEvent.php` r. 41-44 (lock e uscita se `processed`/`ignored`) | `WebhookTest.php` › «checkout.session.completed: il job paga l'ordine una volta sola anche se ripetuto», «due eventi diversi per la stessa sessione…» |
| Webhook, pagina di successo e cron in parallelo: un solo pagamento, biglietti non doppi | SRV `PaymentService::markPaid()` r. 42 (lock ordine); DB `tickets_one_per_item_seq` | `ParallelTest.php` › «webhook, pagina di successo e reinvio in parallelo…»; `PaymentTest.php` › «una conferma ripetuta non emette biglietti doppi» |
| Id evento con prefisso `evt_`, `processed` con data | DB `stripe_events_id_prefix`, `stripe_events_processed_dated` | `SalesSchemaTest.php` › «rifiuta un id senza evt_…», «rifiuta un evento processed senza data» |
| Eventi non gestiti o di ordini sconosciuti: `ignored` con il motivo | JOB `ProcessStripeEvent::apply()` | `WebhookTest.php` › «segna ignored gli eventi che non ci riguardano»; `MoneyEdgeCasesTest.php` › «webhook di un ordine sconosciuto…», «charge.refunded di un pagamento sconosciuto non crea rimborsi»; `StripeClosuresTest.php` › «contestazione o rimborso fallito di un pagamento sconosciuto…» |
| Errore: `failed`, nuovi tentativi | JOB `ProcessStripeEvent::handle()` (5 tentativi) | `WebhookTest.php` › «segna failed con l'errore e riprova» |
| Dopo l'ultimo tentativo avviso allo staff | JOB `failed()` di `ProcessStripeEvent`, `RefundOrderFully`, `SendTicketsEmail`, `GenerateCsvExport` | `Feature/Qa/UncoveredRulesTest.php` › «avvisa lo staff dopo l'ultimo tentativo di ogni job» (4 casi) |
| Pagamento tardivo: riprenota se ci sono posti, altrimenti `needs_refund` e rimborso automatico | SRV `PaymentService::markPaid()` r. 54-63, `CapacityService::rebook()` r. 74 | `PaymentTest.php` › «conferma tardiva con posto libero: riprenota», «conferma tardiva senza posti: needs_refund…»; `LatePaymentTest.php` › «pagamento tardivo senza posti: rimborso automatico…» |
| Rimborso idempotente (una chiamata, una riga) | JOB `Jobs/RefundOrderFully.php` r. 47-61; DB `refunds_idempotency_key_unique`, `refunds_stripe_refund_id_unique` | `PaymentTest.php` › «il rimborso automatico usa l'Idempotency-Key e non si ripete»; `MoneyEdgeCasesTest.php` › «rimborso doppio…», «rimborso parziale dalla Dashboard e poi completo dallo staff: si rimborsa solo il resto» |
| `refunded` solo da `charge.refunded`: posti e prezzo membro liberati, biglietti rimborsati | SRV `PaymentService::recordRefund()` r. 180 | `PaymentTest.php` › «charge.refunded chiude il rimborso…» |
| Rimborso rifiutato da Stripe: ordine da rivedere, biglietti non riattivati | SRV `PaymentService::refundFailed()` r. 113 | `StripeClosuresTest.php` › «refund.failed…», «refund.updated canceled…», «rimborso che Stripe rifiuta subito…», «refund.updated succeeded non tocca nulla» |
| Rimborso con importo positivo e causa (salvo Dashboard) | DB `refunds_amount_positive`, `refunds_cause_required`, `refunds_source_valid` | `Feature/Qa/UncoveredRulesTest.php` › «i CHECK di refunds rifiutano importo, origine e causa non validi» |
| Rimborso dal pannello solo con permesso | POL `OrderPolicy::refund`; `Filament/Resources/Orders/OrderResource.php` `refundAction()` | `Feature/Panel/ActionsTest.php` › «rimborsa un ordine pagato dal pannello…»; `MoneyEdgeCasesTest.php` › «l'organizzatore non può rimborsare dal pannello» |
| Contestazione: biglietti sospesi, vinta riattivati, persa revocati e posti liberati, chiusa per sempre | SRV `Services/DisputeService.php` `apply()` | `StripeClosuresTest.php` › «contestazione aperta sospende i biglietti, vinta li riattiva», «contestazione persa revoca i biglietti e libera i posti…», «i biglietti contestati non escono nell'export del check-in…» |
| Fattura Stripe sui biglietti (indirizzo, partita IVA facoltativa) solo con l'interruttore acceso; id e link salvati sull'ordine | SRV `CheckoutService::invoiceParams()`, `PaymentService::attachInvoice()`; JOB `ProcessStripeEvent::apply()` (`invoice.paid` con `metadata.order_id`) | `Feature/Shop/InvoiceTest.php` › «chiede a Stripe la fattura…», «con un Customer Stripe già noto…», «con l'interruttore spento…», «salva id e link della fattura…» |
| Link della fattura solo al proprietario dell'ordine e allo staff con `orders.view_amounts`; ultima fattura della membership nell'area personale | POL `AccountController::order()` (ordini dalle relazioni dell'utente); `OrderResource::infolist()`; SRV `MembershipService::sync()` | `InvoiceTest.php` › «salva id e link della fattura; il link «Invoice» lo vede solo chi ha fatto l'ordine», «nel pannello il link della fattura…», «invoice.paid della membership…» |
| Riconciliazione: pagato su Stripe e non qui rielaborato una volta; altre differenze segnalate | JOB `Console/Commands/ReconcileStripe.php` | `StripeClosuresTest.php` › riconciliazione (4 test) |

## Biglietti, QR, email

| Regola | Dove | Test |
|---|---|---|
| Un biglietto per nominativo | DB `tickets_one_per_item_seq`; SRV `TicketService::issue()` (`firstOrCreate`) | `OrdersSchemaTest.php` › «rifiuta un secondo biglietto per la stessa riga e numero» |
| Nominativo immutabile; correzione solo firmata, al massimo 3 | TRG `tickets_guard`; DB `tickets_numbers_valid`; SRV `TicketService::correctAttendee()` r. 71; POL `TicketPolicy::correctAttendee` | `OrdersSchemaTest.php` › «non cambia il nominativo», «rifiuta una correzione senza la firma di chi corregge», «ammette 3 correzioni firmate…»; `ActionsTest.php` › «corregge un refuso nel nome (solo admin)…» |
| Tipo, ordine, riga e ruolo del biglietto immutabili | TRG `tickets_guard` | `OrdersSchemaTest.php` › «non cambia il tipo del biglietto» |
| Biglietto mai cancellato; `cancelled` e `refunded` definitivi | TRG `tickets_no_delete`, `tickets_guard`; DB `tickets_cancelled_dated` | `OrdersSchemaTest.php` › «non cancella un biglietto», «non rende di nuovo valido un biglietto rimborsato» |
| Codice breve senza caratteri ambigui | DB `tickets_short_code_format`, `tickets_short_code_unique` | `OrdersSchemaTest.php` › «rifiuta un codice breve con caratteri ambigui» |
| QR firmato Ed25519 con ticket, versione, evento e chiave, senza nome | SRV `TicketService::qrPayload()` | `Feature/Account/TicketTest.php` › «firma il QR con ticket, versione, evento e chiave, senza il nome» |
| QR e biglietti solo del proprietario o dell'intestatario, solo validi | POL `Http/Controllers/AccountController.php` `ticketsOf()`, `qr()` | `AccountTest.php` › «nega ordini e biglietti di un altro utente (IDOR)», «mostra il biglietto anche all'intestatario…»; `MoneyEdgeCasesTest.php` › «QR: niente per i biglietti di altri né per un biglietto rimborsato» |
| Email dei biglietti una volta sola, con limite di invio | DB `sent_messages_dedupe_key_unique`; JOB `Jobs/SendTicketsEmail.php` r. 39, 50 | `TicketTest.php` › «manda all'acquirente tutti i biglietti e a ogni partecipante il suo, una volta sola», «mette le email in coda con il limite di invio» |
| Reinvio solo di biglietti validi, con audit | SRV `TicketService::resend()` r. 52; POL `TicketPolicy::resend` | `Feature/Panel/PermissionsTest.php` › «Resend ticket dal pannello…»; `TicketTest.php` › «eventals:resend-ticket rimanda il biglietto in coda» |
| Omaggi: 0 €, emessi dallo staff con motivo, contano nella capienza, revocabili | DB `orders_comp_shape`; SRV `Services/ComplimentaryService.php`; POL `OrderPolicy::issueComplimentary` | `OrdersSchemaTest.php` › omaggi (2 test); `ActionsTest.php` › «emette un omaggio a 0 €…», «rifiuta l'omaggio a pool pieno e lo revoca…» |
| Un accompagnatore attivo per posto del pass | DB colonna generata `companion_claims.active_key` + `companion_claims_one_per_slot` | `OrdersSchemaTest.php` › «ammette un solo accompagnatore attivo per posto del pass» |
| Export del check-in solo con biglietti validi | SRV `Services/CsvExports.php` | `Feature/Panel/ExportTest.php` › «esporta per il check-in solo i biglietti validi…» |

## Accesso, permessi, registro

| Regola | Dove | Test |
|---|---|---|
| Codice di accesso: 6 cifre, solo HMAC, 10 minuti, una volta, 5 tentativi | SRV `Services/LoginCodeService.php` r. 35-76 | `Feature/Account/LoginCodeTest.php` (5 test) |
| Limite di richieste del codice per email | SRV `LoginCodeService::send()` r. 28 | `LoginCodeTest.php` › «limita le richieste di codice per email» |
| Limite per IP (invio e verifica) | SRV `LoginCodeService` r. 29, 49; rotte `throttle:10,1` | `Feature/Qa/UncoveredRulesTest.php` › «limita per IP le richieste di codice…», «limita per IP le verifiche del codice: 30 l'ora» |
| Al massimo 10 codici al giorno per email (anche da IP diversi) | SRV `LoginCodeService::send()` | `Feature/SecurityTest.php` › «al massimo 10 codici di accesso al giorno per email…» |
| Lo staff non entra con il codice | SRV `LoginCodeService` r. 31, 67 | `LoginCodeTest.php` › «non apre la sessione allo staff…» |
| Staff: password argon2id | `.env` `HASH_DRIVER=argon2id`; `Console/Commands/CreateAdmin.php` | `Feature/StaffAccessTest.php` › «crea un admin con eventals:create-admin» |
| Staff: MFA TOTP obbligatoria | `Providers/Filament/AdminPanelProvider.php` r. 32-34 (`isRequired: true`) | `Feature/Qa/UncoveredRulesTest.php` › «manda alla configurazione della MFA lo staff che non l'ha ancora attivata» |
| Pannello solo allo staff | POL `User::canAccessPanel` | `StaffAccessTest.php` › «non apre il pannello a chi non è dello staff»; `Feature/Panel/PolicyTest.php` › «chiude il pannello a chi compra» |
| Ogni azione del pannello con il suo permesso, mai con il nome del ruolo | POL `Policies/*.php`; seeder `RolesAndPermissionsSeeder` | `PermissionsTest.php` › «ogni ruolo può solo le azioni di §5.3», «i ruoli hanno esattamente i permessi predefiniti…»; `PolicyTest.php` (6 test) |
| Il ruolo admin non si modifica dal pannello | POL `Policies/RolePolicy.php` `update()` | `PermissionsTest.php` › «l'admin cambia i permessi di un ruolo con audit; il ruolo admin non si modifica» |
| Export scaricabili solo da chi li ha chiesti e ha il permesso | POL `Http/Controllers/ExportDownloadController.php` r. 16; rotta con nome file vincolato | `ExportTest.php` › «prepara in coda un export grande, scaricabile solo da chi l'ha chiesto»; `MoneyEdgeCasesTest.php` › «export: nessun accesso al file di un altro staff…» |
| Consensi e registro audit solo in aggiunta | TRG `consents_no_update`, `consents_no_delete`, `audit_log_no_update`, `audit_log_no_delete` | `OtherTablesSchemaTest.php` › «tiene consensi e registro audit solo in aggiunta» |
| Membership: niente secondo Checkout per chi è membro; eventi vecchi ignorati; niente attivazione per un altro utente | SRV `MembershipService` r. 21, 66, `activateFromCheckout()`; DB `memberships_stripe_subscription_id_unique` | `MembershipTest.php` (8 test) |

## Programma e speaker (Fase 2)

| Regola | Dove | Test |
|---|---|---|
| Due sessioni nella stessa sala non si sovrappongono (possono toccarsi) | TRG `program_sessions_no_room_overlap_insert`, `program_sessions_no_room_overlap_update`; SRV `Services/ProgramService.php` `save()` (lock sulla sala) e `overlap()`; form `ProgramSessionResource::roomFree()` | `Feature/Program/ProgramRulesTest.php` › «il trigger rifiuta due sessioni sovrapposte…», «rifiuta la sala occupata con un messaggio…», «accetta sessioni che si toccano…», «dà un errore leggibile se la sala è occupata…»; `ProgramImportTest.php` › «segnala gli errori riga per riga…» |
| Fine dopo l'inizio; sala solo con orari | DB `program_sessions_times_valid`, `program_sessions_room_has_times`, `session_parts_times_valid` | `ProgramRulesTest.php` › «rifiuta una fine non dopo l'inizio e una sala senza orari» |
| Giorno dell'evento ricavato dall'ora di inizio a Lisbona, mai fuori dalle date | SRV `ProgramService::prepare()` | `ProgramRulesTest.php` › «rifiuta un giorno fuori dalle date dell'evento» |
| Codici d'import unici e nel formato del foglio; capienza della sala > 0 | DB `rooms_event_id_code_unique`, `speakers_code_unique`, `program_sessions_event_id_code_unique`, `*_code_format`, `rooms_capacity_positive` | `ProgramRulesTest.php` › «rifiuta codici doppi, codici fuori formato e capienza zero» |
| Import CSV: il codice è la chiave (reimportare aggiorna), righe `EXAMPLE-` saltate, errori riga per riga, una transazione per riga | SRV `Filament/Imports/*Importer.php` | `ProgramImportTest.php` (5 test) |
| `paid=yes`: tipo di biglietto del workshop (spento), pool e prezzo creati una volta sola; poi non si torna a `paid=no` dal foglio | SRV `ProgramSessionImporter::createWorkshopTicket()` | `ProgramImportTest.php` › «workshop: paid=yes crea una volta sola…» |
| Foto degli speaker ritagliate e ricodificate senza EXIF; email mai pubblica | SRV `Services/SpeakerPhoto.php`, `Speaker::booted()`, `#[Hidden(['email'])]` | `ProgramImportTest.php` › «pubblica la foto ricodificata…»; `ProgramPagesTest.php` › «risponde 200…» (`assertDontSee` dell'email) |
| Sul sito solo sessioni pubblicate e speaker con almeno una sessione pubblicata | SRV `ProgramController`, `SpeakerController`, `Speaker::scopeVisible()` | `ProgramPagesTest.php` › «nasconde sessioni non pubblicate e chi parla solo lì» |
| Programma, sale, tracce, speaker e import solo a organizzatore e admin | POL `RoomPolicy`, `TrackPolicy`, `ProgramSessionPolicy` (`import`), `SpeakerPolicy` | `Panel/PermissionsTest.php` › matrice; `Panel/PolicyTest.php` › pagine per ruolo, «mostra l'import del programma solo a chi ha program.import» |

## Più eventi nella stessa installazione (ADR 0011)

| Regola | Dove | Test |
|---|---|---|
| Pagine pubbliche su un solo evento: `EVENTALS_EVENT`, poi il prossimo pubblicato non finito, poi l'ultimo | SRV `Models/Event.php` `current()` | `MultiEventTest.php` › «sceglie l'evento pubblico…» |
| Prezzo nella valuta dell'evento del tipo; fascia dello stesso evento del tipo | TRG `ticket_prices_same_event_insert`, `ticket_prices_same_event_update`; SRV `Models/TicketPrice.php` (valuta dall'evento); pannello `TicketPriceResource` (fasce filtrate) | `MultiEventTest.php` › «i trigger rifiutano prezzi, pool, codici e ordini mescolati…», «fasce e prezzi per evento…» |
| Ordine e `price_data` di Stripe nella valuta dell'evento | TRG `orders_currency_matches_event`; SRV `CheckoutService::reserve()`, `Models/Order.php` | `MultiEventTest.php` › «fasce e prezzi per evento…» |
| Valuta dell'evento fissa dopo il primo prezzo o ordine | TRG `events_currency_locked`; pannello `EventResource` (campo spento, `Event::hasSalesData()`) | `MultiEventTest.php` › «i trigger rifiutano…» |
| Pool di capienza e tipi di un codice sconto nello stesso evento del tipo | TRG `ticket_type_pools_same_event_*`, `discount_code_ticket_types_same_event_*` | `MultiEventTest.php` › «la capienza di un evento non tocca…», «i trigger rifiutano…» |
| Sconto a importo fisso solo nella sua valuta (codici senza evento) | SRV `CheckoutService::findDiscount()`; `Models/DiscountCode.php` (valuta dall'evento) | `MultiEventTest.php` › «un codice a importo fisso vale solo nella sua valuta…» |
| Programma, sessioni e speaker pubblici solo dell'evento corrente | SRV `ProgramController`, `SpeakerController`, `Speaker::scopeVisible($eventId)` | `MultiEventTest.php` › «le pagine pubbliche mostrano solo l'evento corrente…» |
| Export di biglietti e ordini per evento | SRV `CsvExports` (`$eventId`), pagina `Exports` | `MultiEventTest.php` › «gli export di biglietti e ordini sono per evento» |
| Riconciliazione su tutti gli eventi, ogni ordine sul suo | JOB `eventals:reconcile` | `MultiEventTest.php` › «la riconciliazione rielabora gli ordini di entrambi…» |
| Comandi `eventals:*`, alias `eas:*` deprecati | JOB `Console/Commands/*` (`$aliases`) | `MultiEventTest.php` › «i comandi hanno il prefisso eventals:…» |

## Sicurezza (revisione del 12/11/2026, [sicurezza.md](sicurezza.md))

| Regola | Dove | Test |
|---|---|---|
| Webhook rifiutati se manca `STRIPE_WEBHOOK_SECRET` (una firma con chiave vuota la calcola chiunque) | SRV `StripeWebhookController` | `Feature/SecurityTest.php` › «rifiuta i webhook se manca STRIPE_WEBHOOK_SECRET…» |
| CSV: celle che iniziano con `= + - @`, tab o a capo diventano testo | SRV `CsvExports::safe()` | `SecurityTest.php` › «neutralizza nei CSV…»; `ExportTest.php` |
| Membership: 10 richieste al minuto per utente (ogni richiesta chiama Stripe) | rotte `throttle:10,1` in `routes/web.php` | `SecurityTest.php` › «limita le chiamate a Stripe della membership…» |
| Header di sicurezza anche su Apache/LiteSpeed | `public/.htaccess` (`mod_headers`); nginx: `strumenti/server/nginx-eas8.conf` | `SecurityTest.php` › «mette gli header di sicurezza anche su Apache…» |
| Apertura vendite: niente overselling né doppio prezzo membro sotto carico | SRV `CheckoutService`, `CapacityService`, `PaymentService` | `strumenti/carico/carico.php` (prova ripetibile, [carico.md](carico.md)) |

## Regole senza test

| Regola | Proposta |
|---|---|
| Ore del pannello nel fuso dell'evento (form e tabelle con `Event::timezoneOf()`) | solo interfaccia: test Livewire su `PriceTierResource` con un evento a New York |
| Installazione senza trigger: test solo su MariaDB (`SIGNAL` fuori da una procedura) | su MySQL resta da provare a mano: `UncoveredRulesTest.php` › «con DB_TRIGGERS_OPTIONAL…» si salta |
