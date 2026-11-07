# Documentazione tecnica

Autore: Giulio Occhipinti. Aggiornato al 07/11/2026. Per chi sviluppa e mantiene `sito/` (Laravel 13 + Filament 5).

| Documento | Contenuto |
|---|---|
| [database.md](database.md) | Diagramma ER e dizionario dati **generati dal database** (colonne, chiavi, indici unici, colonne generate, CHECK, FK con ON DELETE, trigger). Si rigenera con `php strumenti/genera-doc-db/genera.php` ([istruzioni](../../strumenti/genera-doc-db/README.md)) |
| [stati.md](stati.md) | Macchine a stati di ordine, biglietto, riserva di capienza, rimborso, membership, contestazione, evento Stripe, con chi provoca ogni passaggio |
| [flussi.md](flussi.md) | Diagrammi di sequenza: acquisto, webhook in coda, pagamento tardivo, rimborso dal pannello, membership, riconciliazione, contestazione, login con codice |
| [regole.md](regole.md) | Regole di business → dove sono garantite (vincolo, trigger, servizio, Policy, job) → test che le prova; elenco delle regole senza test |
| [permessi.md](permessi.md) | Matrice ruolo × permesso generata dal seeder, scelte da confermare |
| [adr/](adr/README.md) | Decisioni architetturali, una per file |
| [esercizio.md](esercizio.md) | Installazione e deploy via SSH, cron, webhook Stripe, chiavi, backup e ripristino, migrazioni sicure, monitoraggio, checklist di go-live, cosa fare se |

Database supportati: MySQL **8.0.19+** (serve `ALTER TABLE … DROP CONSTRAINT`) o MariaDB **10.7+** (in pratica 10.11 o
11.4). `database.md` è generato su MariaDB 10.11.

Dopo ogni migrazione: `cd sito && php artisan migrate:fresh --seed && cd .. && php strumenti/genera-doc-db/genera.php`,
poi aggiorna a mano [regole.md](regole.md) se la migrazione aggiunge o toglie un vincolo.

Documenti di progetto: [architettura](../architettura.md), [decisioni](../decisioni.md), [brief](../brief.md).
