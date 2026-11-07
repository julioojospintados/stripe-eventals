# Documentazione del database generata

Autore: Giulio Occhipinti. Versione 1.0 del 07/11/2026.

`genera.php` legge `information_schema` del database **dopo le migrazioni** e riscrive:

- `docs/tecnico/database.md`: diagramma ER in Mermaid e dizionario dati (colonne con tipo, null e default, chiave
  primaria, indici unici e normali, colonne generate con l'espressione, `CHECK`, chiavi esterne con `ON DELETE` e
  `ON UPDATE`, trigger con il corpo);
- il blocco tra `<!-- matrice-permessi:inizio -->` e `<!-- matrice-permessi:fine -->` di `docs/tecnico/permessi.md`:
  matrice ruolo × permesso dalle tabelle di `spatie/laravel-permission` (quindi dopo `db:seed`), con i significati
  presi da `App\Enums\StaffRole` se `sito/vendor/` è installato.

Lo script fa solo `SELECT`: si può lanciare anche su una copia del database di produzione.

## Uso

Dalla radice del repository, dopo ogni migrazione:

```
cd sito && php artisan migrate:fresh --seed && cd ..
php strumenti/genera-doc-db/genera.php
```

Opzioni: `--env=<file .env>` (predefinito `sito/.env`), `--out=<file>` (predefinito `docs/tecnico/database.md`).
Le variabili d'ambiente `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SOCKET` hanno la
precedenza sul file:

```
DB_DATABASE=eas8_copia DB_USERNAME=lettore DB_PASSWORD=… php strumenti/genera-doc-db/genera.php
```

Requisiti: PHP 8.3+ con `pdo_mysql`; MySQL 8.0.19+ o MariaDB 10.7+.

## Dopo una migrazione che crea una tabella

Aggiungi una riga in `descrizioni.php` (a cosa serve la tabella). Se manca, lo script la segnala su stderr e il
documento scrive «(descrizione mancante)».

## Note

- Su MariaDB le colonne JSON sono `longtext` con un `CHECK json_valid()`: lo script le mostra come `json` e non
  elenca quei `CHECK`.
- Il diagramma ER mostra solo le colonne chiave e gli stati, ed esclude le tabelle tecniche di Laravel (`cache`,
  `jobs`, `sessions`…); il dizionario le contiene tutte.
- Con `DB_TRIGGERS_OPTIONAL=true` su un hosting che non permette i trigger, il documento generato lì non ha trigger:
  è il segnale che le difese sono solo nel codice.
