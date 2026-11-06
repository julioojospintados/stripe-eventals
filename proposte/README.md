# Proposte di layout per EAS8

Autore: Giulio Occhipinti. 06/11/2026.

Il prototipo in [`prototipo/`](../prototipo/index.html) segue fedelmente lo stile di eas-aligners.com, come richiesto.
Qui ci sono **tre direzioni visive alternative** per il sito dell'EAS8, da valutare insieme. Tutte usano il logo,
i colori e i dati veri dell'EAS8; cambiano impaginazione, gerarchia e atmosfera.

Ogni proposta per il sito del congresso ha due pagine: la home (`index.html`) e la pagina dei biglietti
(`biglietti.html`). Per aprire tutto: `cd proposte && python3 -m http.server 8000`, poi `http://localhost:8000`.

| Proposta | Idea | Per chi è pensata |
|---|---|---|
| [1 · Notte di Lisbona](layout-1-notte/index.html) | Evoluzione dello stile attuale: scuro, immersivo, un solo colore d'azione (l'oro), l'arancio come firma di Lisbona e delle date, un motivo ispirato alle piastrelle azulejo | Chi vuole continuità con l'immagine di oggi, più ordinata |
| [2 · Clinico](layout-2-clinico/index.html) | Chiaro e preciso, da congresso scientifico internazionale: verde acqua e blu notte, programma per giorno e sala in primo piano, ricerca nel programma | Chi mette al centro contenuti scientifici, crediti e programma |
| [3 · Editoriale](layout-3-editoriale/index.html) | Come il programma stampato di un grande congresso: carta, filetti, numeri enormi per i giorni, titoli in serif | Chi vuole un'immagine riconoscibile e diversa dagli altri congressi |

## Il sito della società ricreato (eas-aligners.com)

Oltre al sito del congresso, in [`sito-originale/`](sito-originale/) c'è il sito della European Aligner Society
ricreato con i suoi testi e **con i colori di oggi**, in tre varianti. Ognuna ha 7 pagine: Home, Congress,
Membership, Events, About, FAQ, Contact. Menu ridotto a 5 voci più Login, le altre nel footer.

| Variante | Tipo | Idea |
|---|---|---|
| [A · Fedele](sito-originale/variante-a-fedele/index.html) | Semplice | La stessa atmosfera di oggi (grafite, arancio, oro, azzurro, blu notte), con gerarchia e spaziature regolari e gli speaker leggibili |
| [B · Chiara](sito-originale/variante-b-chiara/index.html) | Semplice | Gli stessi colori su base chiara, header e footer scuri: aria da società scientifica, testi lunghi comodi da leggere |
| [C · Trasparenze](sito-originale/variante-c-particolare/index.html) | Più originale | Strati traslucidi ispirati agli allineatori, un'arcata disegnata nell'apertura, su telefono un menu in basso a portata di pollice |

Foto degli speaker, loghi degli sponsor e immagini dell'EAS7 sono segnaposto dichiarati: vanno forniti da EAS.
Alcuni piccoli refusi dei testi originali sono stati corretti (per esempio un indirizzo email nelle FAQ): da
confermare.

## Cosa hanno in comune

- Nome, date, luogo e bottone d'acquisto nella prima schermata anche su telefono.
- Menu breve (Programme, Speakers, Tickets, Membership, Login); le altre voci nel footer.
- Un solo colore per l'azione principale, così è sempre chiaro dove comprare.
- Prezzi pubblico e membro e fascia attiva (Early bird solo membri, Regular, Last minute) leggibili senza confusione.
- Accessibilità WCAG 2.2 AA: contrasto, dimensione dei bottoni, uso da tastiera.

## Dopo la scelta

La proposta scelta si applica a tutto il sito (programma, speaker, area personale, pannello) mantenendo le stesse
funzioni del prototipo. Si possono anche combinare elementi di proposte diverse.
