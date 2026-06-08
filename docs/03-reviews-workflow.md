# Product Vault - Reviews workflow

Questo documento descrive la prima versione della sezione Revisioni di Product Vault.

La sezione Revisioni nasce per concentrare in un'unica pagina i candidati prodotto che richiedono controllo, evitando che l'utente debba aprire ogni singolo documento per capire cosa manca, cosa è incerto e cosa può essere confermato.

## Principio guida

La revisione non è un errore del sistema.

Product Vault lavora su documenti reali, OCR imperfetto, righe ambigue, descrizioni abbreviate, EAN mancanti, seriali e prodotti simili. Per questo motivo la revisione manuale è una parte centrale dell'MVP.

La sezione Revisioni deve aiutare l'utente a rispondere velocemente a tre domande:

* questo candidato è davvero un prodotto da salvare?
* la conoscenza disponibile è sufficiente per fidarsi?
* quali candidati richiedono attenzione prima degli altri?

## Obiettivo della pagina `/reviews`

La pagina `/reviews` è un centro operativo per i candidati prodotto.

Serve a:

* vedere candidati pending;
* individuare candidati a bassa affidabilità;
* trovare warning Python o Product Understanding;
* controllare candidati con global facts;
* vedere candidati già revisionati;
* confermare rapidamente candidati validi;
* ignorare candidati non utili;
* aprire il documento di origine;
* aprire il prodotto creato quando esiste;
* ispezionare la conoscenza disponibile tramite drawer.

Non sostituisce completamente la pagina dettaglio documento. La integra con una vista trasversale su più documenti.

## Differenza tra DocumentShow e Reviews

### DocumentShow

La pagina documento è centrata sul singolo file.

È utile per:

* vedere il documento caricato;
* controllare testo estratto;
* vedere righe documento;
* revisionare candidati di quel documento;
* capire il risultato del processing;
* rigenerare candidati quando necessario.

### Reviews

La pagina Revisioni è centrata sui candidati.

È utile per:

* vedere tutti i candidati da controllare;
* filtrare per rischio o stato;
* confrontare candidati provenienti da documenti diversi;
* lavorare più velocemente sulla coda di revisione;
* analizzare segnali Product Understanding in modo aggregato.

Questa distinzione è importante: la pagina documento risponde alla domanda "cosa è successo a questo file?", mentre Revisioni risponde alla domanda "cosa devo controllare ora?".

## Rotta e navigazione

È stata aggiunta la rotta:

* `/reviews`

Nome logico:

* `reviews.index`

La navbar è stata aggiornata per rendere raggiungibile la sezione.

La dashboard include collegamenti verso le revisioni quando ci sono elementi che richiedono attenzione.

## Riepilogo pagina

La pagina mostra un riepilogo operativo con conteggi principali.

Metriche presenti:

* documenti da controllare;
* candidati pending;
* candidati a bassa affidabilità;
* candidati revisionati.

Questi numeri servono a dare all'utente una percezione immediata del carico di lavoro.

## Filtri

La pagina supporta filtri per restringere la lista candidati.

### Pending

Mostra candidati ancora da revisionare.

È il filtro più operativo: rappresenta la coda principale di lavoro.

### Low confidence

Mostra candidati con affidabilità bassa o segnali deboli.

Serve a dare priorità ai casi più rischiosi.

### Python warnings

Mostra candidati che hanno warning derivati dall'analyzer Python o dai guardrail di similarità.

Esempi:

* modello potenzialmente in conflitto;
* specifiche diverse;
* similarity debole;
* global facts assenti o deboli;
* match diagnostico non abbastanza forte.

### Global fact

Mostra candidati collegati a conoscenza globale o candidati per cui è utile controllare il rapporto con i global facts.

Serve a verificare quando la conoscenza globale sta aiutando o quando rischia di essere fuorviante.

### Reviewed

Mostra candidati già confermati o ignorati.

È utile per audit leggero e controllo post-revisione.

## Lista candidati

Ogni riga della lista candidati mostra informazioni sintetiche ma operative.

Elementi principali:

* stato revisione;
* nome candidato;
* badge conoscenza;
* documento di origine;
* venditore;
* prezzo;
* affidabilità;
* riga documento;
* identificazione;
* segnali Product Understanding;
* azioni rapide.

La lista deve restare compatta. L'obiettivo non è mostrare tutti i metadata tecnici nella tabella, ma dare abbastanza informazioni per decidere se aprire il dettaglio conoscenza o agire subito.

## Stato candidato

Gli stati principali sono:

* `pending`;
* `confirmed`;
* `ignored`.

### Pending

Il candidato deve ancora essere controllato.

Può essere confermato o ignorato.

### Confirmed

Il candidato è stato confermato dall'utente.

In genere questo porta alla creazione o collegamento di un prodotto, alla registrazione del feedback e all'eventuale aggiornamento dei global facts.

### Ignored

Il candidato è stato ignorato.

L'ignore è comunque informazione utile: può alimentare feedback e aiutare il sistema a capire quali righe non devono essere proposte con troppa forza in futuro.

## Badge conoscenza

I badge conoscenza sintetizzano i segnali disponibili.

Devono aiutare l'utente a capire rapidamente se il candidato ha:

* EAN;
* global fact;
* feedback precedente;
* match Python utile;
* warning;
* similarity debole;
* conoscenza mancante;
* conflitti di identità.

I badge non devono sostituire il drawer. Servono solo come anteprima.

## Azioni rapide

La pagina offre azioni rapide per ridurre attrito.

### Conferma candidato

Conferma il candidato come prodotto valido.

Effetti principali:

* crea o aggiorna il prodotto;
* collega il documento;
* registra feedback;
* può creare garanzia automatica stimata;
* può registrare eventi lifecycle;
* può contribuire a global facts.

### Ignora candidato

Marca il candidato come non utile.

Effetti principali:

* il candidato non genera prodotto;
* il feedback può essere registrato;
* il sistema può usare questa scelta come segnale futuro.

### Apri revisione documento

Apre il dettaglio documento collegato.

Serve quando il candidato non basta e l'utente deve controllare il contesto completo del file.

### Apri prodotto

Disponibile quando il candidato è già collegato a un prodotto.

Permette di passare rapidamente dalla revisione alla scheda prodotto.

## Drawer "Dettaglio conoscenza"

È stato aggiunto un drawer dedicato all'ispezione del candidato.

Il drawer mostra informazioni più tecniche senza appesantire la tabella principale.

Sezioni principali:

* candidato;
* origine documento;
* global fact attuale;
* snapshot global fact salvato nel candidato;
* feedback;
* Python analysis;
* guardrail identità;
* segnali aggregati;
* metadata tecnici.

## Candidato

La sezione candidato mostra le informazioni principali della proposta.

Esempi:

* nome candidato;
* modello;
* EAN;
* seriale;
* prezzo;
* quantità;
* stato revisione.

Questa sezione risponde alla domanda: "che cosa sto revisionando?".

## Origine documento

La sezione origine documento mostra il contesto da cui arriva il candidato.

Può includere:

* documento;
* merchant;
* data;
* riga documento;
* testo riga;
* stato processing;
* link al documento.

Questa sezione risponde alla domanda: "da dove è uscito questo candidato?".

## Global fact attuale

La sezione global fact attuale mostra la conoscenza disponibile nel sistema al momento della consultazione.

Può includere:

* EAN;
* nome canonico;
* categoria;
* line type;
* conteggi conferme;
* conteggi ignore;
* registration rate;
* confidence globale;
* segnali correnti.

Questa sezione non coincide necessariamente con ciò che era salvato nel candidato al momento della generazione.

## Snapshot global fact candidato

Lo snapshot è ciò che il candidato aveva nei metadata quando è stato generato o analizzato.

È importante conservarlo perché permette debug e audit.

Esempio:

* al momento della generazione non esisteva un global fact;
* il candidato mostrava `missing_global_facts`;
* dopo la conferma, il sistema ha creato un global fact;
* la conoscenza attuale ora esiste.

Lo snapshot storico deve restare visibile nei metadata tecnici, ma non deve sempre generare warning attivi.

## Feedback

La sezione feedback mostra segnali derivati da conferme o ignore precedenti.

Può includere:

* product identity score;
* registration preference score;
* suggested bias;
* review hint;
* identity signals;
* preference signals.

Il feedback aiuta a capire se il candidato somiglia a elementi già confermati o ignorati.

## Python analysis

La sezione Python analysis mostra il risultato dell'analyzer Python.

Può includere:

* best match;
* canonical name suggerito;
* similarity score;
* metodo di matching;
* confidence;
* warning;
* dati diagnostici.

Il risultato Python deve essere interpretato con cautela. Una similarity testuale non è una prova definitiva di identità prodotto.

## Guardrail identità

I guardrail aiutano a evitare falsi positivi.

Esempi:

* model conflict;
* spec difference;
* OCR variant;
* high similarity;
* weak global facts;
* no global facts;
* match debole.

La UI deve distinguere tra guardrail critici e segnali solo diagnostici.

## Segnali aggregati

La sezione segnali aggregati raccoglie ciò che il sistema usa per spiegare la qualità del candidato.

Esempi:

* EAN match;
* feedback positivo;
* feedback negativo;
* global fact trovato;
* similarity forte;
* similarity debole;
* OCR variant;
* modello in conflitto;
* categoria suggerita.

Questa sezione è utile per debug e per capire perché il sistema mostra un certo badge.

## Metadata tecnici

I metadata tecnici servono a sviluppo, debug e audit leggero.

Possono includere JSON e snapshot completi.

Non devono essere la prima cosa mostrata all'utente, ma devono restare accessibili durante la fase MVP perché aiutano a capire errori di parsing, Product Understanding e UI.

## Correzione warning `missing_global_facts`

È stata corretta una situazione ambigua.

Problema:

* un candidato nasceva senza global facts;
* nei metadata storici veniva salvato `missing_global_facts`;
* dopo la conferma, il candidato poteva generare un global fact;
* la UI continuava a mostrare `missing_global_facts` come warning attivo anche se la conoscenza globale attuale esisteva.

Comportamento corretto:

* se esiste conoscenza globale attuale, il warning storico non deve essere mostrato come warning attivo;
* lo snapshot storico può restare visibile nei metadata tecnici;
* la UI deve distinguere stato storico e stato attuale.

Questa distinzione evita messaggi contraddittori nel drawer.

## Similarity Python debole

È stata declassata la visualizzazione dei match Python deboli.

Problema:

* Python similarity può proporre best match poco pertinenti;
* match intorno al 35-40% rischiano di sembrare utili anche quando sono solo rumore;
* mostrarli nella UI come conoscenza utile peggiora la fiducia dell'utente.

Comportamento attuale:

* match sotto soglia non viene mostrato come match utile;
* può restare visibile come dato diagnostico;
* la UI evita di presentarlo come suggerimento affidabile.

Il problema a monte resta aperto e sarà corretto più avanti nel Product Understanding.

## Candidati confermati e drawer

Un candidato confermato può avere uno stato diverso rispetto al momento in cui è stato analizzato.

Per questo il drawer deve tenere insieme:

* snapshot candidato;
* stato revisione;
* prodotto collegato;
* global fact attuale;
* feedback aggiornato.

La UI non deve giudicare un candidato confermato solo in base ai warning salvati prima della conferma.

## UX: compattezza e chiarezza

La pagina Revisioni deve restare compatta.

Regole UX:

* la tabella deve mostrare solo sintesi operative;
* i dettagli tecnici devono stare nel drawer;
* i badge devono essere brevi;
* le azioni devono essere vicine al candidato;
* i warning devono essere pochi e significativi;
* i match deboli non devono sembrare raccomandazioni;
* i candidati confermati non devono continuare a sembrare problematici se la conoscenza attuale li ha risolti.

## Relazione con Dashboard

La dashboard mostra segnali sintetici sulle revisioni.

Esempi:

* documenti da controllare;
* garanzie da controllare;
* link verso `/reviews`.

La dashboard non deve duplicare il lavoro della pagina Revisioni. Deve solo portare l'utente verso la pagina corretta.

## Relazione con Product Understanding

La pagina Revisioni è la principale UI di Product Understanding.

Product Understanding produce segnali, punteggi, global facts, feedback e warning.

Revisioni li rende utilizzabili dall'utente.

La qualità della pagina dipende da due aspetti:

* qualità dei segnali prodotti a monte;
* chiarezza con cui vengono mostrati.

Per questo motivo la pagina deve evitare di mostrare ogni dato tecnico con lo stesso peso.

## Relazione con warranty lifecycle

La conferma candidato può avviare il flusso prodotto e garanzia.

Quando l'utente conferma un candidato:

* viene creato o collegato un prodotto;
* può essere generata una garanzia stimata;
* vengono registrati eventi lifecycle.

Quindi la pagina Revisioni non è solo una lista di approvazione: è un punto di ingresso nel ciclo vita prodotto.

## Cosa non risolvere ora

Per MVP non conviene ancora fare refactor grandi.

Da evitare per ora:

* estrazione completa di componenti condivisi tra DocumentShow e ReviewIndex;
* riscrittura della UI con un sistema generico di metadata;
* motore regole avanzato per tutti i warning;
* normalizzazione completa di ogni metadata JSON;
* automazioni aggressive di conferma candidato.

Il sistema funziona come prima versione. Prima va consolidato e documentato.

## Problemi noti

### Duplicazione con DocumentShow

Parte della logica di visualizzazione candidato è simile tra pagina documento e pagina Revisioni.

Questo è accettabile per ora.

In futuro si potrà valutare:

* componenti Blade condivisi;
* presenter o view model;
* service per normalizzare segnali UI;
* mapping centralizzato dei badge.

### Troppi metadata tecnici

Durante MVP è utile vedere molti metadata.

In futuro la UI dovrà diventare più selettiva.

### Similarity debole gestita in UI

La UI declassa match deboli, ma il problema dovrebbe essere risolto a monte nell'analyzer Python.

### Snapshot e stato attuale

La distinzione è stata corretta per `missing_global_facts`, ma va mantenuta come principio generale per tutti i warning storici.

## Test e verifiche

La sezione Revisioni è indirettamente coperta dal test Product Understanding per la parte dati e segnali.

Comando principale:

```
php artisan product-vault:test-understanding
```

Comando collegato al flusso post-conferma:

```
php artisan product-vault:test-warranty-lifecycle
```

Per modifiche UI, verificare manualmente:

* apertura `/reviews`;
* filtri;
* badge conoscenza;
* drawer dettaglio conoscenza;
* candidato pending;
* candidato confermato;
* candidato ignorato;
* candidato con global fact;
* candidato con warning Python;
* candidato con similarity debole;
* azione conferma;
* azione ignora;
* link documento;
* link prodotto.

## Checklist manuale dopo modifiche a Reviews

Prima di considerare chiusa una patch su Revisioni, verificare:

* la pagina carica senza overflow;
* i filtri non rompono la query;
* la tabella resta compatta;
* i badge sono leggibili;
* il drawer si apre correttamente;
* lo snapshot storico è distinguibile dalla conoscenza attuale;
* `missing_global_facts` non appare come warning attivo se esiste global fact attuale;
* i match Python deboli non sono presentati come suggerimenti utili;
* conferma candidato non chiude o rompe il contesto in modo indesiderato;
* il prodotto collegato è raggiungibile quando esiste.

## Backlog specifico Revisioni

### P0 - Consolidamento

* Mantenere corretta la distinzione tra snapshot storico e conoscenza attuale.
* Evitare warning attivi fuorvianti su candidati confermati.
* Continuare a nascondere o declassare similarity Python debole.
* Verificare compattezza della tabella dopo ogni aggiunta.

### P1 - Miglioramenti utili

* Ridurre duplicazione tra DocumentShow e ReviewIndex.
* Migliorare mapping dei badge conoscenza.
* Migliorare messaggi per candidati ignorati.
* Migliorare filtri e ordinamento.
* Aggiungere eventuale ricerca testuale candidato/documento.
* Migliorare stato vuoto per filtri senza risultati.

### P2 - Evoluzione futura

* Coda di revisione guidata.
* Azioni bulk.
* Priorità automatica dei candidati.
* Suggerimenti di revisione più esplicativi.
* Storico decisioni per candidato.
* Metriche su conferme e ignore.
* Backoffice per global facts e feedback.
* Componenti UI condivisi tra documento, prodotto e revisioni.

## Decisione strategica

La sezione Revisioni è chiusa come prima versione funzionale MVP.

Non è definitiva, ma svolge il suo compito:

* rende visibili i candidati da controllare;
* espone la conoscenza Product Understanding;
* consente azioni rapide;
* collega documento, candidato, prodotto e garanzia;
* supporta debug e apprendimento.

La priorità ora non è aggiungere altra UI alla pagina Revisioni, ma documentare i test, consolidare il backlog e poi progettare la knowledge base iniziale.
