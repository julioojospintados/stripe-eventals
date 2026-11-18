# Prezzi, fasce e apertura delle vendite

Autore: Giulio Occhipinti. Aggiornato al 18/11/2026.

## La regola

**Prezzo = tipo di biglietto × fascia attiva in quel momento × ruolo (pubblico o membro).**

- **Fascia attiva:** al massimo una per evento in ogni momento (`starts_at` inclusa, `ends_at` esclusa, `active`
  acceso). Nessuna fascia attiva = vale il prezzo base.
- **Prezzo:** si cerca il prezzo del tipo per la fascia attiva e il ruolo; se manca, il prezzo **base** (senza
  fascia) per quel ruolo. Il membro senza un prezzo suo paga il pubblico, e paga il membro solo se non supera il
  pubblico. **Senza prezzo pubblico il tipo non si compra**, nemmeno dai membri.
- **Fascia fissata sull'ordine:** prezzo, ruolo e fascia si fissano quando il cliente riserva i posti. Se la fascia
  scade mentre paga, paga la fascia della riserva.
- **Un solo biglietto a prezzo membro** per persona, evento e tipo: quello intestato al membro che compra. Gli altri
  biglietti dello stesso ordine pagano il prezzo pubblico.
- **In vendita** (`TicketType::onSale()`, l'unica regola usata da checkout, pagine pubbliche, prezzi mostrati e
  pannello): tipo attivo, `sales_start` impostata e passata, `sales_end` vuota o futura, evento pubblicato con le
  vendite dell'evento aperte (`sales_open_at`, `sales_close_at`). **Senza data d'inizio non è in vendita.**

Codice: `app/Services/PriceResolver.php` (fascia e prezzo), `app/Services/CheckoutService.php` (riserva, prezzo
membro, sconto), `app/Models/TicketType.php` (`onSale()`). Nel pannello la tabella **Prezzo oggi** (pagina Ticket
types e cruscotto, `app/Filament/Widgets/PricesToday.php`) usa lo stesso `PriceResolver`: per ogni tipo pubblico e
ruolo mostra se è in vendita (e perché no), la fascia attiva con la sua fine, il prezzo, o «Nessun prezzo: non
acquistabile».

## Esempio: il pass dell'EAS8 (seeder)

| Prezzo | Fascia | Ruolo | Importo |
|---|---|---|---|
| base | — | pubblico | 695 € |
| base | — | membro | 395 € |
| early bird | `early_bird` (01/11/2026 – 01/02/2027) | membro | 276,50 € |

- Il 06/12/2026 (early bird attiva): il pubblico paga 695 € (non c'è un prezzo pubblico early bird, vale il base),
  il membro 276,50 €. L'early bird è quindi **solo per i membri**.
- Il 01/03/2027 (regular attiva, senza prezzi suoi): pubblico 695 €, membro 395 €.
- Un membro che compra due pass: 276,50 € il suo, 695 € l'altro.

## Configurare un evento da zero (pannello `/admin`)

1. **Event → New:** titolo, slug, stato *draft* finché non è pronto, date, fuso, valuta, giorni. `sales_open_at` e
   `sales_close_at` sono facoltative (vuote = nessun limite a livello di evento).
2. **Sales → Ticket types:** un tipo per ogni biglietto (pass, giornaliero, workshop), canale *public*, massimo per
   ordine. Lascia **vuota la data d'inizio**: così non si vende per sbaglio mentre configuri.
3. **Sales → Price tiers:** le fasce (es. `early_bird`, `regular`) con date che non si sovrappongono.
4. **Sales → Prices:** per ogni tipo almeno il **prezzo base pubblico**; poi i prezzi membro e quelli per fascia.
5. **Sales → Capacity pools:** i posti (un pool per giorno, uno per workshop) e collegali ai tipi (campo Pools del
   tipo).
6. **Apertura:** controlla la tabella *Prezzo oggi* (nessun «Nessun prezzo»), metti l'evento in *published* e scrivi
   la **data d'inizio** sui tipi. Da quel momento sono in vendita.

Eliminare: prezzi, fasce, tipi, capienze, codici sconto, sale, tracce, sessioni, speaker ed eventi si eliminano solo
se nessun ordine, biglietto, riserva o utilizzo li usa (`app/Services/ConfigDeletion.php`); altrimenti il pannello
dice «Usato da N ordini: disattivalo invece di eliminarlo». Prova del percorso completo:
`tests/Feature/Panel/SetupJourneyTest.php`.
