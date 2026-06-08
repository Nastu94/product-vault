# Product Vault - Backlog tecnico

Questo documento raccoglie il backlog tecnico di Product Vault dopo il completamento della prima versione dei blocchi Product Understanding, Warranty lifecycle e Reviews.

Il backlog non è una lista di desideri generica. Serve a distinguere:

* cosa va corretto prima di una demo seria;
* cosa migliora l'MVP ma non blocca;
* cosa è evoluzione futura;
* cosa non va fatto ora per evitare dispersione.

## Principio guida

Product Vault deve crescere per micro-patch.

Ogni patch deve avere un obiettivo chiaro, verifiche precise e impatto limitato.

Il rischio principale in questa fase è confondere il consolidamento MVP con la costruzione del prodotto definitivo. L'MVP deve diventare affidabile nei suoi flussi centrali prima di aggiungere nuove grandi aree.

## Stato attuale sintetico

Sono presenti prime versioni funzionanti di:

* upload e gestione documenti;
* estrazione testo e OCR/fallback;
* parsing righe;
* candidati prodotto;
* Product Understanding;
* feedback workspace;
* global facts;
* analyzer Python;
* conferma/ignore candidato;
* creazione prodotto;
* creazione garanzia stimata;
* lifecycle events;
* pagina prodotto;
* pagina garanzie;
* dashboard;
* pagina Revisioni;
* test Product Understanding;
* test warranty lifecycle.

Queste parti non sono definitive, ma sono abbastanza mature da poter essere consolidate invece di riscritte.

## Priorità P0

Le priorità P0 sono interventi da affrontare prima di considerare Product Vault presentabile come demo MVP affidabile.

### P0.1 - Python similarity troppo permissiva

Problema:

L'analyzer Python può proporre best match deboli, circa 35-40%, verso prodotti senza relazione reale.

Mitigazione attuale:

* la UI nasconde o declassa questi match;
* il drawer li può mostrare solo come diagnostica.

Perché resta P0:

Il problema è a monte. Se un match debole entra nei metadata come se fosse utile, rischia di sporcare UI, debug e future decisioni automatiche.

Obiettivo:

* distinguere chiaramente match usabile e match diagnostico;
* non restituire `best_match` utile sotto soglia;
* evitare match basati solo su token comuni.

Possibili patch:

* soglia minima per `usable_match`;
* token overlap minimo;
* penalizzazione parole comuni;
* blocco se non ci sono token informativi condivisi;
* campo separato `diagnostic_best_match`;
* test fixture dedicata.

Verifica:

```
php artisan product-vault:test-understanding
```

### P0.2 - Guardrail lessicali per match prodotto

Problema:

La similarità testuale da sola può essere alta anche quando il prodotto è diverso.

Esempi:

* generazione diversa;
* modello diverso;
* numero porte diverso;
* capacità diversa;
* brand incompatibile;
* categoria incompatibile.

Obiettivo:

* rafforzare guardrail su modello, specifiche e brand;
* evitare canonicalizzazioni aggressive;
* trattare conflitti come warning forti.

Possibili patch:

* normalizzazione token modello;
* rilevamento differenze `Gen 10` vs `Gen 11`;
* rilevamento numeri specifica;
* confronto brand quando presente;
* fixture per prodotti simili ma diversi.

Verifica:

```
php artisan product-vault:test-understanding
```

### P0.3 - Parser order confirmation

Problema:

Nei documenti e-commerce o conferme ordine, i numeri presenti nel nome o modello prodotto possono essere interpretati come quantità, prezzo o totale.

Esempi concettuali:

* generazione prodotto;
* capacità memoria;
* numero porte;
* codice modello;
* versione;
* dimensioni schermo.

Rischio:

* quantità errata;
* prezzo errato;
* totale errato;
* candidato distorto;
* Product Understanding alimentato da dati sporchi.

Obiettivo:

* distinguere numeri descrittivi da numeri economici;
* migliorare parsing `order_confirmation`;
* evitare amount-based troppo aggressivo.

Possibili patch:

* parser dedicato per conferme ordine;
* regole più strette su prezzo;
* riconoscimento valuta obbligatorio per importi;
* contesto colonna o label;
* fixture sintetica basata sul documento smoke 19.

Verifica:

```
php artisan product-vault:test-understanding
```

Smoke manuale consigliato:

* ricaricare o riesaminare documento smoke conferma ordine;
* controllare righe, quantità, prezzo e candidati.

### P0.4 - Merchant parser che legge intestazioni tecniche

Problema:

In alcuni PDF smoke il venditore viene letto come "Righe documento".

Rischio:

* merchant errato;
* UI poco credibile;
* documenti classificati bene ma con fonte sbagliata;
* dati merchant inutili per futuri segnali.

Obiettivo:

* evitare che intestazioni tabellari o sezioni tecniche diventino merchant;
* migliorare scoring del merchant parser.

Possibili patch:

* blacklist controllata di label tecniche;
* preferenza per prime righe reali del documento;
* uso di P.IVA, domini, indirizzi o ragione sociale;
* punteggio negativo per parole come "righe documento", "descrizione", "quantità", "prezzo".

Verifica:

```
php artisan product-vault:test-understanding
```

Più verifica manuale su documenti smoke.

### P0.5 - Classification documenti non pertinenti

Problema:

Un documento non pertinente può essere classificato come receipt o tipo simile.

Mitigazione attuale:

* non genera righe;
* non genera candidati;
* per MVP1 è accettabile.

Perché è comunque P0/P1 borderline:

Se la UI comunica male il risultato, l'utente può perdere fiducia. Il comportamento più importante è non generare prodotti falsi, ma anche la classificazione dovrebbe migliorare.

Obiettivo minimo MVP:

* mantenere 0 candidati sui documenti non pertinenti;
* mostrare messaggio chiaro;
* evitare che un documento non pertinente sembri uno scontrino valido.

Possibili patch:

* segnali negativi per documenti non prodotto;
* classifier con stato `unknown` o `unsupported`;
* fixture documento non pertinente;
* copy UI più chiaro.

Verifica:

```
php artisan product-vault:test-understanding
```

### P0.6 - Knowledge base iniziale

Problema:

Product Vault non può dipendere solo da caricamenti manuali dell'utente per diventare utile.

Senza conoscenza iniziale, il primo utilizzo può sembrare troppo povero.

Obiettivo:

* progettare un sistema per iniettare conoscenza base in database pulito;
* mantenere conoscenza controllata, testabile e non inventata;
* distinguere seed tecnico, fixture e knowledge pack reale;
* evitare che dati sintetici di test diventino conoscenza di produzione senza controllo.

Possibili componenti:

* knowledge pack versionato;
* seeder dedicato;
* categorie iniziali;
* brand iniziali;
* alias controllati;
* esempi EAN solo se affidabili;
* regole di import;
* comando di verifica;
* documentazione.

Verifica:

```
php artisan product-vault:test-understanding
```

Più eventuale comando dedicato futuro.

## Priorità P1

Le priorità P1 migliorano l'MVP ma non devono bloccare il consolidamento dei P0.

### P1.1 - Ridurre duplicazione tra DocumentShow e ReviewIndex

Problema:

Parte della logica di visualizzazione candidato è duplicata tra dettaglio documento e pagina Revisioni.

Perché non è P0:

La duplicazione è accettabile finché il comportamento è stabile. Un refactor prematuro rischia di rompere UI funzionante.

Obiettivo futuro:

* estrarre componenti condivisi;
* centralizzare mapping badge;
* centralizzare normalizzazione segnali UI;
* mantenere differenze UX tra documento e revisioni.

Possibili soluzioni:

* Blade component per candidate summary;
* presenter/view model;
* service per knowledge badges;
* mapping centralizzato dei warning.

### P1.2 - Migliorare badge conoscenza

Problema:

I badge devono sintetizzare molti segnali senza confondere l'utente.

Obiettivo:

* distinguere segnale forte, warning, diagnostica e stato storico;
* ridurre rumore visivo;
* mostrare meno badge ma più significativi.

Possibili miglioramenti:

* badge per EAN forte;
* badge per global fact attuale;
* badge per warning attivo;
* badge per match diagnostico;
* badge per feedback positivo/negativo.

### P1.3 - Migliorare stati vuoti e messaggi UX

Problema:

Quando non ci sono candidati, garanzie o risultati filtro, la UI deve spiegare cosa sta succedendo.

Obiettivo:

* stati vuoti più chiari;
* suggerimenti contestuali;
* copy coerente con il principio "non inventare dati".

Aree coinvolte:

* `/reviews`;
* `/warranties`;
* ProductShow;
* DocumentShow;
* dashboard.

### P1.4 - Migliorare pagina garanzie

Obiettivo:

* filtri più utili;
* migliore evidenza scadenze;
* distinzione garanzia stimata/manuale;
* fonte e confidence più leggibili;
* stato "da controllare" più chiaro.

### P1.5 - Migliorare storico prodotto

Obiettivo:

* eventi più leggibili;
* metadata evento essenziali;
* link verso documento/garanzia quando applicabile;
* separazione tra eventi automatici e manuali.

### P1.6 - Aggiungere fixture per casi corretti

Ogni bug corretto dovrebbe diventare una fixture.

Casi candidati:

* order confirmation;
* merchant parser;
* documento non pertinente;
* similarity debole soppressa;
* token overlap;
* brand incompatibile;
* modello simile ma diverso.

### P1.7 - Migliorare logging pipeline

Obiettivo:

* rendere più leggibili i `document_processing_attempts`;
* distinguere errori, warning e fallback;
* aiutare debug su documenti reali;
* non salvare dati sensibili inutili nei log.

## Priorità P2

Le priorità P2 sono evoluzioni importanti ma non necessarie per consolidare l'MVP attuale.

### P2.1 - Notifiche garanzia

Possibili sviluppi:

* reminder garanzia in scadenza;
* preferenze notifica;
* scheduler;
* notifiche email;
* dashboard alert.

Non va fatto prima di avere garanzie e scadenze affidabili.

### P2.2 - Barcode

Il barcode è utile, soprattutto quando lo scontrino è povero.

Possibili sviluppi:

* scansione lato client;
* upload foto barcode;
* ZBar o ZXing;
* associazione a prodotto;
* uso come segnale forte Product Understanding.

Non deve diventare obbligatorio nel primo upload.

### P2.3 - Backoffice global facts

Possibili sviluppi:

* lista global facts;
* merge/split global facts;
* correzione canonical name;
* gestione alias;
* monitor falsi match;
* statistiche conferme/ignore.

Da fare solo quando i global facts iniziano ad accumularsi.

### P2.4 - Knowledge pack avanzato

Possibili sviluppi:

* versionamento;
* import/export;
* validazione qualità;
* categorie e brand;
* alias merchant;
* alias prodotto;
* dataset non sensibile;
* strumenti admin.

Prima serve progettare il formato minimo.

### P2.5 - Parsing certificati garanzia

Possibili sviluppi:

* riconoscimento certificati;
* garanzia commerciale;
* estensioni garanzia;
* date inizio/fine da testo;
* associazione a prodotto esistente.

Non è prioritario finché il flusso acquisto -> prodotto -> garanzia stimata non è stabile.

### P2.6 - Documenti riparazione e assistenza

Possibili sviluppi:

* parsing documenti riparazione;
* creazione eventi assistenza;
* costo riparazione;
* centro assistenza;
* stato prodotto.

Importante per la visione prodotto, ma non per il consolidamento immediato.

### P2.7 - Audit log completo

Il progetto ha già una direzione chiara su privacy e audit, ma il logging completo può arrivare dopo il consolidamento dei flussi core.

Da includere in futuro:

* accessi file;
* modifiche prodotto;
* modifiche garanzia;
* azioni admin;
* eliminazioni;
* support access.

### P2.8 - Retention e cancellazione dati

Possibili sviluppi:

* soft delete strutturato;
* cancellazione media;
* richieste eliminazione account;
* export dati;
* retention configurabile.

Tema importante, ma non deve bloccare la demo tecnica MVP locale.

### P2.9 - Multi-account avanzato

Jetstream Teams e Spatie con `team_id` sono già base corretta.

Evoluzioni future:

* famiglia;
* negozio;
* staff;
* ruoli più granulari;
* inviti;
* limiti piano per workspace.

Non va complicato ora se l'uso principale resta personale.

## Decisioni aperte

### Knowledge base iniziale

Domande aperte:

* deve essere solo seeder Laravel o anche file dati versionati?
* dove conservare i dati iniziali?
* quanto devono essere reali i dati?
* come evitare dati inventati o non verificabili?
* come distinguere fixture, seed tecnico e knowledge pack?
* come aggiornare la knowledge base senza sporcare dati utente?

Direzione probabile:

* file versionati controllati;
* comando artisan dedicato;
* import idempotente;
* dati minimi ma utili;
* test dedicati.

### Soglie Python similarity

Domande aperte:

* qual è la soglia minima per un match usabile?
* la soglia deve cambiare se esiste EAN?
* la soglia deve cambiare per categoria?
* un match debole deve essere restituito come diagnostico?
* come calcolare token overlap minimo?

Direzione probabile:

* separare `usable_match` da `diagnostic_match`;
* non mostrare best match debole come suggerimento;
* introdurre fixture prima di alzare soglie aggressive.

### Parser documenti e-commerce

Domande aperte:

* creare parser separato per `order_confirmation`?
* riusare invoice parser con regole diverse?
* quando classificare un documento come conferma ordine?
* quali numeri sono descrittivi e quali economici?

Direzione probabile:

* parser dedicato o strategia dedicata;
* importi riconosciuti solo con contesto forte;
* fixture basata sul caso smoke 19.

### Classificazione documento non pertinente

Domande aperte:

* usare `unknown` o `unsupported`?
* quando suggerire eliminazione?
* come evitare messaggi allarmanti?
* quali segnali negativi devono pesare di più?

Direzione probabile:

* per MVP è sufficiente evitare candidati falsi;
* migliorare poi copy e classificazione.

## Cose da non fare ora

Questa sezione è importante quanto il backlog.

### Non riscrivere tutta la pipeline documenti

La pipeline funziona abbastanza per MVP.

Serve correggere punti specifici, non rifare tutto.

### Non costruire un motore AI generico

Product Vault non deve diventare dipendente da AI obbligatoria.

Prima consolidare:

* regole;
* parsing;
* OCR;
* revisione;
* feedback;
* knowledge base.

### Non automatizzare conferme prodotto aggressive

Il sistema deve aiutare la revisione, non saltarla quando i dati sono incerti.

Conferme automatiche aggressive rischiano di creare prodotti falsi e garanzie sbagliate.

### Non fare refactor UI grandi

La UI ha difetti e duplicazioni, ma funziona.

Meglio correggere problemi concreti prima di estrarre componenti generici.

### Non normalizzare subito tutti i metadata JSON

I metadata JSON sono utili in MVP per debug e sperimentazione.

Normalizzare troppo presto può irrigidire un modello ancora in evoluzione.

Normalizzare solo quando un dato diventa business-critical, interrogabile o stabile.

### Non costruire subito marketplace, vendita usato o B2B

Sono fuori ambito MVP.

Prima Product Vault deve funzionare bene per:

* documento;
* prodotto;
* garanzia;
* revisione;
* conoscenza.

### Non aggiungere notifiche prima di avere scadenze affidabili

Le notifiche sono utili, ma diventano fastidiose se la scadenza è sbagliata.

Prima migliorare garanzie e confidence.

### Non creare knowledge base enorme non verificata

Una knowledge base grande ma sporca peggiora il prodotto.

Meglio piccola, controllata, idempotente e testabile.

### Non confondere fixture con dati reali

Le fixture servono a testare.

Non devono diventare automaticamente conoscenza di produzione.

## Sequenza consigliata dopo documentazione

Dopo aver completato i documenti interni, la sequenza consigliata è:

1. aggiornare README come indice leggero;
2. progettare knowledge base iniziale;
3. creare documento tecnico sul knowledge pack;
4. implementare primo seed/import knowledge base;
5. correggere Python similarity debole;
6. aggiungere fixture per similarity debole;
7. correggere parser `order_confirmation`;
8. aggiungere fixture per order confirmation;
9. correggere merchant parser;
10. aggiungere fixture per merchant parser;
11. migliorare classificazione non pertinente.

Questa sequenza può cambiare se emerge un bug bloccante, ma evita di saltare subito tra UI, parser, Python, garanzie e knowledge base senza priorità.

## Criteri per promuovere un task

Un task può salire di priorità quando:

* genera prodotti falsi;
* genera garanzie sbagliate;
* rompe test principali;
* rende la revisione confusa;
* sporca global facts;
* riduce fiducia dell'utente;
* blocca la demo MVP;
* impedisce di costruire knowledge base iniziale.

Un task può restare basso quando:

* è solo rifinitura UI;
* richiede refactor ampio;
* riguarda funzioni future;
* non impatta i flussi principali;
* non produce dati sbagliati;
* non ha ancora casi reali sufficienti.

## Checklist prima di iniziare una patch

Prima di ogni patch chiedersi:

* qual è il problema preciso?
* quale comportamento atteso vogliamo ottenere?
* quali file tocchiamo?
* esiste già una fixture?
* serve aggiungere una fixture?
* come verifichiamo?
* rischiamo di rompere Product Understanding?
* rischiamo di rompere warranty lifecycle?
* è davvero necessario ora?

Se la risposta non è chiara, prima scrivere diagnosi o documentazione, non codice.

## Comandi di verifica standard

Pulizia cache:

```
php artisan optimize:clear
```

Test Product Understanding:

```
php artisan product-vault:test-understanding
```

Test warranty lifecycle:

```
php artisan product-vault:test-warranty-lifecycle
```

Stato Git:

```
git status
```

## Regola finale

Il backlog deve guidare lo sviluppo, non accumulare ansia.

Se tutto sembra importante, la priorità reale torna a essere:

1. non creare dati falsi;
2. non sporcare conoscenza globale;
3. mantenere revisione chiara;
4. mantenere test verdi;
5. migliorare un problema alla volta.