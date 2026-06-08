# Product Vault - Warranty lifecycle

Questo documento descrive la prima versione del sistema garanzie e lifecycle prodotto di Product Vault.

Il blocco garanzie nasce dopo la conferma di un candidato prodotto: quando l'utente conferma che una riga documento rappresenta davvero un prodotto, il sistema può creare una scheda prodotto, collegarla al documento di acquisto e generare una garanzia stimata quando i dati disponibili lo permettono.

## Principio guida

Product Vault non deve presentare la garanzia stimata come certezza legale assoluta.

La piattaforma può calcolare una scadenza probabile sulla base di regole configurabili, data acquisto, categoria e paese, ma la UI e la logica devono mantenere il concetto di stima.

La garanzia deve quindi essere:

* tracciabile;
* modificabile manualmente;
* collegata alla fonte;
* accompagnata da confidence score;
* idempotente nella generazione automatica;
* distinguibile tra calcolata, manuale o derivata da documento.

## Ruolo nel flusso MVP

Il flusso principale è:

1. il documento viene caricato;
2. il sistema estrae testo, righe e candidati;
3. l'utente conferma un candidato;
4. il sistema crea o aggiorna il prodotto;
5. il sistema collega prodotto e documento;
6. il sistema registra feedback Product Understanding;
7. il sistema prova a creare una garanzia automatica stimata;
8. il sistema registra eventi lifecycle;
9. la garanzia diventa visibile in prodotto, lista prodotti, dashboard e pagina garanzie.

Il blocco garanzia non deve anticipare la conferma prodotto. Una garanzia automatica creata su un candidato incerto rischierebbe di produrre scadenze fuorvianti.

## Entità principali

### Product

Il prodotto rappresenta il bene posseduto dall'utente o dal workspace.

Può derivare da un candidato confermato oppure essere creato manualmente in futuro.

Campi rilevanti per garanzia:

* `purchase_date`;
* `purchase_price`;
* `category_id`;
* `merchant_id`;
* `ean_code`;
* `serial_number`;
* relazione con documenti;
* relazione con garanzie;
* storico eventi lifecycle.

### Document

Il documento è la prova o fonte informativa.

Nel caso di una garanzia automatica, il documento collegato al prodotto è normalmente una prova d'acquisto.

Non tutti i documenti creano garanzie:

* scontrino/fattura: possono alimentare garanzia;
* conferma ordine: può alimentare garanzia, ma richiede cautela;
* manuale: non crea garanzia;
* documento riparazione: crea evento lifecycle, non necessariamente garanzia;
* documento non pertinente: non crea garanzia;
* certificato garanzia: in futuro potrà creare o aggiornare garanzie commerciali.

### Warranty

La garanzia rappresenta una copertura collegata a un prodotto.

Campi concettuali rilevanti:

* prodotto;
* tipo garanzia;
* data inizio;
* data fine;
* durata mesi;
* fonte;
* confidence score;
* note o metadata;
* timestamps.

La garanzia può essere generata automaticamente o gestita manualmente.

### WarrantyRule

Le regole garanzia permettono di evitare logica hardcoded in controller o view.

Una regola può dipendere da:

* paese;
* categoria;
* tipo account;
* tipo garanzia;
* durata;
* stato attivo;
* priorità o specificità;
* nota fonte.

Per MVP è stata introdotta una regola legale italiana di base a 24 mesi.

### ProductLifecycleEvent

Gli eventi lifecycle descrivono cosa è successo nella vita del prodotto.

Esempi:

* prodotto creato da candidato;
* garanzia calcolata automaticamente;
* garanzia creata manualmente;
* garanzia modificata manualmente;
* futuro documento di riparazione;
* futura assistenza;
* futura vendita o dismissione.

Lo storico rende Product Vault più di un archivio file: diventa una cronologia leggibile del prodotto.

## Service principali

### DefaultWarrantyCreator

`DefaultWarrantyCreator` è il service responsabile della creazione automatica della garanzia stimata.

Responsabilità:

* ricevere un prodotto;
* verificare se esiste già una garanzia compatibile;
* evitare duplicazioni;
* leggere la data acquisto;
* individuare la regola garanzia applicabile;
* calcolare data inizio e data fine;
* creare la garanzia con fonte e confidence;
* registrare evento lifecycle quando appropriato.

Comportamento attuale:

* usa `purchase_date`;
* usa `WarrantyRule`;
* crea una garanzia stimata di default quando la regola lo permette;
* usa durata 24 mesi per la regola legale italiana;
* imposta `source = calculated`;
* imposta `confidence_score = 70`;
* è idempotente;
* non duplica garanzie già presenti.

### ProductFromCandidateCreator

`ProductFromCandidateCreator` è coinvolto nella conferma del candidato.

Responsabilità nel flusso garanzia:

* creare il prodotto dal candidato confermato;
* collegare prodotto e documento;
* salvare dati principali del prodotto;
* registrare feedback Product Understanding;
* invocare la creazione della garanzia automatica;
* registrare eventi lifecycle.

Questo service è il punto di passaggio tra revisione candidato e vita reale del prodotto.

### ProductLifecycleEventRecorder

`ProductLifecycleEventRecorder` centralizza la registrazione degli eventi prodotto.

Eventi già gestiti:

* prodotto creato da candidato;
* garanzia calcolata automaticamente;
* garanzia creata manualmente;
* garanzia modificata manualmente.

Il recorder evita di spargere la logica di storico tra controller, Livewire component e service diversi.

## Creazione automatica garanzia

La creazione automatica avviene dopo la conferma del candidato, non durante la generazione del candidato.

Condizioni principali:

* il candidato viene confermato;
* il prodotto viene creato o aggiornato;
* esiste una `purchase_date`;
* esiste una regola garanzia applicabile;
* non esiste già una garanzia equivalente per il prodotto.

Risultato atteso:

* viene creata una garanzia stimata;
* viene salvata durata;
* viene salvata fonte `calculated`;
* viene salvato confidence score;
* viene registrato evento lifecycle.

## Idempotenza

L'idempotenza è obbligatoria.

Confermare o rieseguire parti del flusso non deve creare garanzie duplicate.

Esempio di comportamento corretto:

1. il candidato viene confermato;
2. viene creata una garanzia;
3. il flusso viene rieseguito o testato di nuovo;
4. il sistema rileva che la garanzia esiste già;
5. non viene creata una seconda garanzia equivalente.

Questo è fondamentale perché il processing documenti e le azioni di revisione possono essere rieseguiti durante sviluppo, debug o recovery.

## Prodotto senza purchase_date

Se il prodotto non ha una data acquisto affidabile, il sistema non deve inventare una scadenza.

Comportamento corretto:

* non creare garanzia automatica;
* lasciare spazio alla creazione manuale;
* mostrare nella UI che la garanzia è da verificare o assente;
* permettere all'utente di inserire manualmente date e durata.

La mancanza di `purchase_date` non deve bloccare la creazione del prodotto.

## Tipi di garanzia

Per MVP il tipo principale è la garanzia legale stimata.

Tipi concettuali previsti:

* `legal`;
* `commercial`;
* `extended`;
* `repair_extension`;
* `unknown`.

La garanzia legale stimata non deve impedire in futuro l'aggiunta di:

* garanzia commerciale del produttore;
* estensione acquistata;
* garanzia da riparazione;
* garanzia derivata da certificato separato.

## Fonti garanzia

La fonte è importante quanto la data.

Fonti concettuali:

* `calculated`: calcolata da Product Vault;
* `manual`: inserita o modificata dall'utente;
* `document_text`: derivata da testo documento;
* `merchant`: derivata da dati merchant;
* `manufacturer`: derivata da produttore;
* `unknown`: fonte non chiara.

Nel MVP attuale la fonte automatica principale è `calculated`.

## Confidence score

La garanzia automatica ha un confidence score.

Attualmente la garanzia calcolata automaticamente usa `confidence_score = 70`.

Questo valore comunica che:

* la stima è utile;
* la fonte è ragionevole;
* non è una certezza assoluta;
* l'utente può correggerla.

In futuro il confidence score potrà variare in base a:

* qualità documento;
* data acquisto certa o incerta;
* categoria;
* paese;
* presenza di certificato garanzia;
* merchant affidabile;
* conferma manuale utente.

## UI prodotto

La pagina prodotto mostra la sezione garanzia.

Comportamenti principali:

* se esiste una garanzia, viene mostrata;
* l'utente può modificarla manualmente;
* se non esiste, l'utente può crearla manualmente;
* lo storico mostra eventi collegati alla garanzia.

La modifica manuale deve registrare un evento lifecycle, perché cambia una scadenza rilevante del prodotto.

## Lista prodotti

La lista prodotti mostra lo stato garanzia in tabella.

Obiettivo UX:

* far capire rapidamente quali prodotti hanno garanzia;
* evidenziare scadenze;
* permettere accesso veloce alla scheda prodotto;
* evitare che l'utente debba aprire ogni prodotto per sapere se è coperto.

## Pagina garanzie

La rotta `/warranties` contiene una pagina dedicata alle garanzie.

Funzionalità presenti:

* riepilogo;
* filtri;
* tabella;
* link al prodotto;
* link al documento quando disponibile.

Questa pagina serve come centro operativo per controllare scadenze e coperture.

## Dashboard

La dashboard include elementi legati alle garanzie.

Elementi attuali:

* card garanzie in scadenza;
* box "Garanzie da controllare";
* link verso la pagina garanzie o verso elementi da revisionare.

La dashboard non deve sostituire la pagina garanzie. Deve solo evidenziare ciò che richiede attenzione.

## Storico prodotto

Lo storico prodotto mostra eventi lifecycle.

Eventi attuali:

* prodotto creato da candidato;
* garanzia calcolata automaticamente;
* garanzia creata manualmente;
* garanzia modificata manualmente.

Lo storico è importante perché un prodotto può accumulare nel tempo:

* documenti;
* garanzie;
* riparazioni;
* assistenza;
* note;
* cambiamenti manuali;
* future vendite o dismissioni.

## Comando di test

Il comando principale è:

```
php artisan product-vault:test-warranty-lifecycle
```

Il test copre:

* creazione garanzia automatica;
* idempotenza;
* regole categoria;
* prodotto senza `purchase_date`;
* eventi lifecycle automatici;
* eventi lifecycle manuali.

Questo comando deve restare verde dopo modifiche a:

* conferma candidato;
* creazione prodotto;
* garanzie;
* regole garanzia;
* eventi lifecycle;
* UI che modifica garanzie.

## Relazione con Product Understanding

Product Understanding e warranty lifecycle sono collegati, ma non coincidono.

Product Understanding risponde alla domanda:

* questo candidato rappresenta davvero un prodotto?

Warranty lifecycle risponde alla domanda:

* una volta confermato il prodotto, quale garanzia stimata o manuale dobbiamo associare?

La garanzia non deve essere generata solo perché Product Understanding ha un match alto. Serve una conferma prodotto o una fonte sufficientemente affidabile.

## Relazione con documenti

Il documento resta la prova.

Un prodotto può avere più documenti collegati:

* prova d'acquisto;
* garanzia;
* manuale;
* riparazione;
* foto seriale;
* conferma ordine;
* documento assistenza.

La garanzia dovrebbe sempre essere interpretabile rispetto alle sue fonti.

Nel MVP attuale il caso più importante è:

* candidato confermato da scontrino/fattura;
* prodotto creato;
* documento collegato come prova;
* garanzia legale stimata creata da `purchase_date`.

## Casi da non automatizzare ora

Per evitare complessità premature, non automatizzare ancora:

* calcolo garanzia da condizioni testuali complesse;
* distinzione legale completa tra prodotti nuovi, usati, ricondizionati e B2B;
* gestione paesi multipli avanzata;
* garanzie commerciali produttore da fonti esterne;
* estensioni garanzia lette da ogni possibile documento;
* reclami automatici;
* notifiche legali assertive.

Questi temi sono importanti, ma non devono bloccare il consolidamento MVP.

## Problemi e rischi

### Rischio: garanzia presentata come certezza

Mitigazione:

* usare wording come "stimata";
* mostrare fonte e confidence;
* permettere modifica manuale;
* evitare promesse legali assolute.

### Rischio: duplicazione garanzie

Mitigazione:

* mantenere idempotenza nel creator;
* testare riesecuzioni;
* verificare garanzie esistenti prima della creazione.

### Rischio: data acquisto errata

Mitigazione:

* non creare garanzia se la data manca;
* permettere correzione manuale;
* in futuro distinguere data acquisto, data ordine e data consegna.

### Rischio: categoria errata

Mitigazione:

* usare regola default solo quando ragionevole;
* permettere modifica manuale;
* in futuro ricalcolare o suggerire aggiornamento quando cambia categoria.

### Rischio: troppe regole troppo presto

Mitigazione:

* mantenere regole semplici;
* non costruire subito un motore legale completo;
* aggiungere complessità solo quando i casi reali lo richiedono.

## Backlog specifico garanzie

### P0 - Consolidamento MVP

* Verificare wording UI: usare sempre "garanzia stimata" quando la fonte è `calculated`.
* Assicurare idempotenza in ogni percorso di conferma candidato.
* Mantenere verde `product-vault:test-warranty-lifecycle`.
* Evitare creazione automatica senza `purchase_date`.

### P1 - Miglioramenti utili

* Migliorare badge garanzia in lista prodotti.
* Aggiungere filtri più utili nella pagina `/warranties`.
* Migliorare messaggi per prodotti senza garanzia.
* Distinguere meglio data acquisto e data consegna.
* Aggiungere note manuali sulla garanzia.
* Mostrare fonte e confidence in modo più chiaro.

### P2 - Evoluzione futura

* Parsing certificati garanzia.
* Supporto garanzie estese.
* Supporto garanzie commerciali produttore.
* Notifiche scadenza garanzia.
* Scheduler reminder.
* Regole per paese/categoria più granulari.
* Eventi di riparazione e assistenza.
* Allegati multipli per garanzia.
* Export riepilogo prodotto/garanzia.

## Decisione strategica

Nel MVP la garanzia è utile se aiuta l'utente a ricordare e organizzare, non se prova a sostituire una consulenza legale.

La direzione corretta è:

* creare automaticamente solo stime ragionevoli;
* rendere tutto modificabile;
* mantenere traccia della fonte;
* costruire storico prodotto;
* usare i documenti come prova collegata;
* migliorare gradualmente con casi reali.

Il valore del sistema non è solo calcolare una data, ma collegare documento, prodotto, garanzia e storico in un'unica scheda revisionabile.