# Risposte di Simone alle domande di struttura (11/11/2026)

Autore: Giulio Occhipinti. Risposte di Simone Li Gregni al modulo «Domande di struttura».

| Tema | Risposta | Cosa cambia |
|---|---|---|
| Script di verifica dell'hosting | Non serve: server con nginx, PHP alla versione che si vuole, MariaDB alla versione che si vuole, SSH | Non è un hosting condiviso limitato: si possono scegliere PHP 8.4 e MariaDB 11.4 e configurare i trigger e un processo per la coda |
| Deploy | `git pull` via SSH | Lo script di deploy va offerto anche in modalità semplice con `git pull` |
| Ambiente di prova | Sì, separato | Configurazione di staging con chiavi Stripe di test e email intercettate |
| Convenzioni del codice | Vanno bene Pint, Larastan livello 5 e la CI | Nessun cambio |
| Stripe in test | Prova Simone con le sue chiavi di test | Nessun accesso da parte nostra |
| Fatture | Partono da Stripe (Xero non collegato) | Abilitare la creazione delle fatture nel Checkout dei biglietti; la membership le ha già con Billing |
| QR per il partner del check-in | Non sa, chiede al partner | Restano QR firmato più export CSV |
| Email | SPF e DKIM configurati | Nessun cambio |
| Ruoli e permessi | Vanno bene; l'admin deve poter modificare dal pannello i permessi dei ruoli | Già presente: pagina «Roles and permissions», con registro delle modifiche; il ruolo admin non si modifica per non chiudersi fuori |
