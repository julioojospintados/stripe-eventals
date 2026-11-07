# ADR 0003: `price_data` al posto dei Price Stripe, sconti calcolati dal server

Autore: Giulio Occhipinti. Stato: accettata. Data: 05/11/2026. Fonte: `docs/decisioni.md` (righe del 05/11/2026 su `price_data`, codici sconto e cumulo), `docs/architettura.md` §6.3, §6.4.

## Contesto

I prezzi dipendono da tipo × fascia × ruolo e i codici sconto si applicano riga per riga, con la regola «migliore tra
prezzo membro e prezzo scontato, mai entrambi». Checkout accetta un solo coupon per sessione e non distingue righe dello
stesso prodotto. Tenere Product e Price allineati in Stripe raddoppia la sorgente dei prezzi.

## Decisione

- Biglietti: ogni riga della Checkout Session usa `price_data` con l'importo unitario già scontato calcolato da
  `CheckoutService` dal database; `allow_promotion_codes: false`.
- Ruolo, fascia e importi si fissano sull'ordine alla riserva (trigger `orders_guard`).
- Utilizzi dei codici contati con `UPDATE … WHERE redeemed_count < max_redemptions` nella transazione della riserva,
  restituiti se l'ordine non si paga.
- Solo la membership usa un Price ricorrente vero (ID nel pannello o in `STRIPE_MEMBERSHIP_PRICE`).

## Alternative

- Product e Price per tipo e fascia in Stripe: due sorgenti dei prezzi da tenere allineate.
- Coupon e Promotion Code Stripe: non esprimono lo sconto riga per riga.

## Conseguenze

- Una sola sorgente dei prezzi: il database. Il browser non manda mai importi.
- I report di Stripe vedono righe con nome libero (`stripe_line_name`), non Product: i dati di vendita si leggono dal
  database.
- Se l'importo pagato non coincide con il totale, l'ordine va in `needs_review` (nessun biglietto finché lo staff non
  decide).
