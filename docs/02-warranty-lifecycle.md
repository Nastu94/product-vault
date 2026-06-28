# Product Vault - Warranty lifecycle

Questo documento descrive il sistema di coperture e lifecycle prodotto di Product Vault, inclusa la contestualizzazione introdotta nella Fase 4.

Il blocco nasce dopo la conferma di un candidato prodotto: quando l'utente conferma che una riga documento rappresenta davvero un prodotto, il sistema può creare una scheda prodotto, collegarla al documento di acquisto e generare una copertura stimata quando i dati disponibili lo permettono.

Una copertura non è descritta soltanto da inizio e fine. Product Vault distingue ora:

* stato e provenienza della copertura;
* periodo temporale indicato;
* contesto dell'acquisto;
* informazioni ancora mancanti;
* conferma dell'utente;
* criterio usato per il calcolo iniziale.

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

## Separazione tra copertura e periodo

Product Vault distingue due dimensioni indipendenti.

### Stato della copertura

Lo stato della copertura descrive quanto sappiamo sulla sua origine e conferma:

* `estimated`: copertura stimata da una regola configurata;
* `declared`: copertura dichiarata in un documento o da una fonte informativa;
* `user_confirmed`: dati confermati o modificati dall'utente;
* `verified`: copertura verificata da una fonte considerata sufficiente;
* `cancelled`: copertura annullata;
* `unknown`: stato non determinabile.

Lo stato della copertura non descrive se il periodo è attualmente in corso.

### Stato temporale

Lo stato temporale descrive esclusivamente le date registrate:

* `not_started`: il periodo non è ancora iniziato;
* `active`: la data di riferimento rientra nel periodo indicato;
* `expiring`: il periodo termina entro 30 giorni;
* `expired`: il periodo è terminato;
* `unknown`: inizio o fine non sono disponibili.

Una copertura può quindi essere contemporaneamente:

* `estimated` come stato della copertura;
* `active` come stato temporale.

La dicitura “nel periodo indicato” non certifica che la copertura sia giuridicamente applicabile.

## Coverage context versionato

La Fase 4 introduce nei metadata della garanzia il contratto:

```php
'coverage_context' => [
    'version' => 'v1',
    'state' => 'estimated',

    'purchase' => [
        'use' => 'unknown',
        'seller_type' => 'unknown',
    ],

    'product' => [
        'condition' => 'unknown',
    ],

    'jurisdiction' => [
        'country_code' => 'IT',
    ],

    'dates' => [
        'purchased_at' => '2026-06-10',
        'delivered_at' => null,
        'starts_at_source' => 'product.purchase_date',
    ],

    'declared_coverage' => [
        'present' => null,
    ],

    'confirmation' => [
        'applied' => false,
        'confirmed_at' => null,
        'confirmed_by_user_id' => null,
    ],
];
```

Il contratto è persistito nei metadata per evitare una migrazione prematura e permettere evoluzioni versionate.

Le informazioni contestuali principali sono:

* uso personale, professionale o aziendale;
* venditore professionale o privato;
* prodotto nuovo, usato o ricondizionato;
* paese rilevante;
* data di acquisto;
* data di consegna;
* origine della data iniziale;
* presenza di una copertura dichiarata;
* conferma dell'utente.

I valori mancanti restano espliciti come `unknown` o `null`. Il sistema non deve inventare informazioni per completare il contesto.


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
* coverage context versionato;
* stato della copertura;
* conferma utente;
* contesto dell'acquisto;
* provenienza delle date;
* timestamps.

La garanzia può essere generata automaticamente o gestita manualmente.

Il periodo `starts_at` / `ends_at` e lo stato della copertura sono concetti separati. Le date descrivono un intervallo registrato; non certificano da sole l'applicabilità della copertura.

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

### WarrantyCoverageContextResolver

`WarrantyCoverageContextResolver` è la fonte centralizzata e read-only per presentare una copertura.

Responsabilità:

* risolvere lo stato della copertura;
* risolvere lo stato temporale;
* normalizzare il contesto persistito;
* supportare garanzie legacy senza `coverage_context`;
* esporre tipo, provenienza e periodo;
* indicare le informazioni mancanti;
* indicare le azioni disponibili;
* non modificare garanzia o metadata.

Il contratto restituito usa la versione:

warranty_coverage_context_v1

Le principali superfici applicative usano il resolver:

dettaglio prodotto;
lista prodotti;
pagina garanzie;
dashboard.
ManualWarrantyCoverageContextBuilder

ManualWarrantyCoverageContextBuilder costruisce il contesto persistito quando l'utente crea o modifica una copertura.

Responsabilità:

preservare metadata e provenienza già presenti;
normalizzare enum, paese, date e booleani;
registrare la conferma dell'utente;
impostare lo stato user_confirmed;
consentire la cancellazione esplicita di valori;
mantenere compatibilità con metadata legacy.

Una modifica manuale non trasforma automaticamente la copertura in verified.

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

La pagina prodotto mostra separatamente:

* stato della copertura;
* stato temporale;
* tipo di copertura;
* inizio, fine e durata;
* provenienza;
* confidence score tecnico;
* contesto dell'acquisto;
* criterio applicato;
* informazioni mancanti;
* documento sorgente;
* note.

L'utente può:

* creare una copertura manualmente;
* modificare una copertura esistente;
* compilare uso dell'acquisto, venditore, condizione, paese e consegna;
* indicare se la copertura è dichiarata nel documento;
* confermare i dati salvati.

La modifica manuale:

* imposta lo stato `user_confirmed`;
* registra utente e timestamp;
* conserva i valori precedenti in `manual_override`;
* registra un evento lifecycle;
* non equivale a una verifica legale o del venditore.

## Lista prodotti

La lista prodotti mostra in modo sintetico:

* stato della copertura;
* stato temporale;
* tipo;
* periodo indicato;
* provenienza;
* informazioni mancanti;
* indicazione esplicita delle stime.

La lista non usa più formule come “giorni residui di garanzia” per una copertura non verificata. Mostra invece la distanza dalla fine del periodo indicato.

## Pagina garanzie

La rotta `/warranties` è il centro operativo delle coperture.

Funzionalità presenti:

* riepilogo dei periodi;
* filtri temporali;
* filtro per provenienza;
* stato della copertura distinto dal periodo;
* indicazione delle stime;
* numero di informazioni mancanti;
* confidence score presentato come dato tecnico;
* link al prodotto;
* link al documento sorgente.

I conteggi temporali sono mutuamente esclusivi:

* nel periodo;
* in scadenza;
* non ancora iniziato;
* scaduto;
* non determinabile.

## Dashboard

La dashboard evidenzia le coperture con periodi che terminano entro 30 giorni.

Il conteggio e la lista usano la stessa finestra temporale:

* periodo già iniziato;
* data finale uguale o successiva alla data corrente;
* data finale entro 30 giorni.

Ogni elemento mostra separatamente:

* stato della copertura;
* stato temporale;
* tipo;
* data finale del periodo;
* provenienza;
* stima da verificare;
* conferma dell'utente;
* informazioni mancanti.

La dashboard non certifica una copertura e non sostituisce la pagina garanzie.

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

I comandi principali sono:

```
php artisan product-vault:test-warranty-lifecycle
php artisan product-vault:test-warranty-coverage-context
php artisan product-vault:test-manual-warranty-coverage-context
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

`product-vault:test-warranty-coverage-context` verifica:

* compatibilità con garanzie legacy;
* stati della copertura;
* stati temporali;
* normalizzazione del contesto;
* informazioni mancanti;
* azioni disponibili;
* comportamento read-only.

`product-vault:test-manual-warranty-coverage-context` verifica:

* creazione manuale;
* aggiornamento di contesti esistenti;
* preservazione della provenienza;
* normalizzazione degli input;
* cancellazione esplicita dei valori;
* date non valide;
* booleani provenienti dal form;
* assenza di mutazioni sui metadata in ingresso.

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
* determinazione legale automatica basata su prodotto nuovo, usato, ricondizionato o acquisto B2B;
* applicazione automatica di normative nazionali complete;
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

## Stato della Fase 4

La Fase 4 — Product Coverage Context è completata.

Risultati:

* persistenza versionata di `coverage_context`;
* stato della copertura separato dallo stato temporale;
* compatibilità con garanzie legacy;
* contesto automatico iniziale;
* contesto manuale confermato dall'utente;
* preservazione della provenienza;
* resolver centralizzato read-only;
* raccolta contestuale nel dettaglio prodotto;
* presentazione coerente in prodotto, lista prodotti, garanzie e dashboard;
* filtri temporali non sovrapposti;
* test dedicati;
* wording che non presenta i 24 mesi come certezza universale.

## Backlog specifico garanzie

### P1 - Miglioramenti successivi

* Introdurre una vera azione di verifica con fonte e operatore.
* Gestire lo stato `declared` da certificati o testo documento.
* Gestire lo stato `cancelled` tramite workflow utente.
* Distinguere in modo più avanzato acquisto, ordine, consegna e attivazione.
* Aggiungere notifiche sui periodi in scadenza.
* Ridurre la duplicazione delle classi visuali dei badge.
* Aggiungere test specifici per controller e componenti Livewire.
* Valutare una migrazione strutturata quando il contratto metadata sarà stabile.

### P2 - Evoluzione futura

* Parsing certificati di garanzia.
* Supporto garanzie estese.
* Supporto garanzie commerciali del produttore.
* Regole per paese e categoria più granulari.
* Eventi di riparazione e assistenza.
* Estensioni conseguenti a riparazione.
* Allegati multipli per copertura.
* Export riepilogo prodotto, documenti e coperture.
* Motore legale opzionale basato su fonti aggiornate e verificabili.

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