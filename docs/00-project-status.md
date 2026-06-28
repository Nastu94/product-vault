# Product Vault - Stato progetto

Questo documento fotografa lo stato tecnico e funzionale attuale di Product Vault durante la chiusura della **Fase 4 — Product Coverage Context**.

Il progetto è ancora in fase MVP, ma dispone già di una base applicativa funzionante per:

* caricamento e processing dei documenti;
* OCR e parsing;
* classificazione e righe documento;
* generazione e revisione dei candidati prodotto;
* Product Understanding;
* creazione dei prodotti;
* coperture e garanzie;
* lifecycle events;
* revisioni manuali;
* dashboard e pagine operative.

La Fase 4 è stata implementata sul branch `pv-product-coverage-context`.

Al momento dell’aggiornamento di questo documento:

* le micro-patch applicative della Fase 4 sono state completate;
* i test dedicati sono risultati verdi durante le verifiche intermedie;
* la documentazione conclusiva è in aggiornamento;
* la regressione finale del branch deve ancora essere eseguita;
* il branch non è ancora stato integrato in `main`.

## Contesto tecnico

* Repository: `Nastu94/product-vault`
* Ambiente locale: Windows + Laragon
* Stack backend: Laravel 13.11.2, PHP 8.4.7
* Stack frontend: Livewire, Tailwind, Vite
* Autenticazione e workspace: Laravel Jetstream Teams
* Permessi: Spatie Permission con `team_id`
* Branch consolidato: `main`
* Branch della fase in chiusura: `pv-product-coverage-context`
* Fase corrente: Fase 4 — Product Coverage Context

## Regole operative di sviluppo

Lo sviluppo procede tramite micro-patch.

Ogni patch deve essere:

* piccola;
* verificabile;
* possibilmente isolata su un solo tema;
* accompagnata da test mirati;
* priva di modifiche non necessarie.

Evitare refactor ampi quando non sono necessari per consolidare l’MVP.

Quando viene aggiunta logica PHP non banale, il codice deve contenere commenti chiari, soprattutto in:

* service;
* comandi Artisan;
* componenti Livewire;
* controller;
* punti decisionali della pipeline.

Principi applicativi da preservare:

* documento e prodotto sono entità distinte;
* la revisione manuale resta centrale;
* la conoscenza iniziale non deve sostituire la verifica;
* gli automatismi devono essere tracciabili;
* i metadata grezzi devono essere preservati;
* le patch non devono essere adattate a un singolo documento;
* le correzioni devono essere generalizzabili;
* una copertura stimata non deve essere presentata come certezza legale.

Non vanno committati:

* `.env`;
* file caricati manualmente;
* file generati in `storage`;
* virtual environment Python;
* output OCR temporanei;
* file temporanei di debug;
* documenti locali non destinati alla repository.

## Comandi di verifica principali

Pulizia cache Laravel:

```bash
php artisan optimize:clear
```

Test Product Understanding:

```bash
php artisan product-vault:test-understanding
```

Test warranty lifecycle e coverage context:

```bash
php artisan product-vault:test-warranty-lifecycle
php artisan product-vault:test-warranty-coverage-context
php artisan product-vault:test-manual-warranty-coverage-context
php artisan product-vault:test-product-confirmation-idempotency
```

Regressione documentale:

```bash
php artisan product-vault:regression-documents
```

Validazione batch controllati:

```bash
php artisan product-vault:validate-document-batch
```

I comandi dedicati verificano separatamente:

* Product Understanding;
* lifecycle delle garanzie;
* risoluzione read-only del coverage context;
* persistenza del contesto manuale;
* idempotenza della conferma prodotto;
* stabilità della pipeline documentale;
* compatibilità con i batch di test controllati.

## Moduli completati come prima versione MVP

### Autenticazione, workspace e permessi

Il progetto usa Jetstream Teams come workspace attivo.

Spatie Permission è configurato con contesto `team_id`, così che ruoli e permessi siano valutati rispetto al team corrente.

Il ruolo iniziale principale è `account_owner`.

### Dashboard

La dashboard mostra:

* riepiloghi operativi;
* documenti recenti;
* prodotti recenti;
* revisioni aperte;
* coperture con periodi in scadenza;
* collegamenti alle aree principali.

Durante la Fase 4 il blocco coperture è stato aggiornato per distinguere:

* stato della copertura;
* stato temporale;
* tipo;
* provenienza;
* informazioni mancanti;
* stima da verificare;
* conferma utente.

Il conteggio e la lista dei periodi in scadenza usano la stessa finestra temporale.

### Documenti

È presente il modulo documenti con:

* upload;
* salvataggio file;
* estrazione testo;
* OCR e fallback;
* classificazione;
* parsing;
* righe documento;
* candidati prodotto;
* dettaglio documento;
* azioni di revisione;
* rigenerazione controllata dei candidati;
* protezione delle decisioni già confermate, modificate o rifiutate.

Il flusso documento resta volutamente revisionabile.

Il sistema non deve creare dati affidabili senza conferma quando l’identificazione è incerta.

### Product Understanding

È presente una prima knowledge base operativa basata su:

* feedback workspace;
* global facts;
* EAN;
* seriali;
* similarità testuale;
* analyzer Python con RapidFuzz;
* initial knowledge;
* metadata diagnostici salvati sui candidati;
* guardrail per conflitti di modello;
* differenze specifiche;
* varianti OCR;
* match deboli;
* segnali aggregati per la revisione.

Esistono comandi dedicati per:

* generare conoscenza sintetica;
* aggiornare la knowledge base iniziale;
* verificare fuzzy matching;
* testare scenari controllati;
* validare documenti reali e sintetici.

La conoscenza aiuta il riconoscimento, ma non sostituisce la revisione manuale.

### Prodotti

La conferma di un candidato genera o collega un prodotto.

Il prodotto conserva, quando disponibili:

* nome;
* modello;
* EAN;
* seriale;
* prezzo;
* data di acquisto;
* venditore;
* categoria;
* documenti di origine;
* eventi lifecycle;
* coperture collegate.

Il flusso di conferma è stato irrigidito per preservare:

* idempotenza;
* candidato di origine;
* decisioni già prese;
* metadata diagnostici;
* collegamento univoco tra candidato e prodotto.

### Warranty lifecycle e Product Coverage Context

È stata completata la prima versione del ciclo delle coperture.

Funzionalità presenti:

* creazione automatica di una copertura stimata dopo la conferma del candidato;
* uso di `WarrantyRule`;
* regola legale italiana di base a 24 mesi;
* idempotenza nella creazione automatica;
* creazione manuale;
* modifica manuale;
* contesto dell’acquisto compilabile;
* persistenza versionata nei metadata;
* resolver centralizzato read-only;
* compatibilità con garanzie legacy;
* presentazione coerente nelle principali superfici applicative.

La regola italiana a 24 mesi è una stima tecnica iniziale.

Non viene presentata come:

* certezza universale;
* verifica legale;
* dichiarazione del venditore;
* conferma del produttore.

Il sistema distingue due dimensioni.

#### Stato della copertura

Descrive provenienza e livello di conferma:

* `estimated`;
* `declared`;
* `user_confirmed`;
* `verified`;
* `cancelled`;
* `unknown`.

#### Stato temporale

Descrive esclusivamente il periodo registrato:

* `not_started`;
* `active`;
* `expiring`;
* `expired`;
* `unknown`.

Una copertura può quindi essere contemporaneamente:

* `estimated` come stato della copertura;
* `active` come stato temporale.

La presenza di un periodo attivo non certifica che la copertura sia applicabile.

#### Coverage context

Il coverage context può contenere:

* uso personale, professionale o aziendale;
* venditore professionale o privato;
* prodotto nuovo, usato o ricondizionato;
* paese rilevante;
* data di acquisto;
* data di consegna;
* origine della data iniziale;
* presenza di una copertura dichiarata;
* conferma utente.

I valori mancanti restano espliciti come `unknown` o `null`.

Il sistema non inventa informazioni per completare il contesto.

#### Persistenza e compatibilità

Il contesto è salvato nei metadata con un contratto versionato.

Questo consente di:

* evitare una migrazione prematura;
* supportare garanzie legacy;
* evolvere il contratto in modo controllato;
* preservare provenance e dati precedenti;
* separare la persistenza dal modello di presentazione.

#### Service principali

`DefaultWarrantyCreator`:

* crea la copertura automatica stimata;
* applica la regola disponibile;
* persiste il contesto iniziale;
* evita duplicazioni.

`WarrantyCoverageContextResolver`:

* legge il contesto;
* normalizza garanzie nuove e legacy;
* risolve stato della copertura;
* risolve stato temporale;
* espone informazioni mancanti;
* espone azioni disponibili;
* non modifica la garanzia.

`ManualWarrantyCoverageContextBuilder`:

* costruisce il contesto manuale;
* preserva metadata e provenienza;
* normalizza gli input;
* gestisce cancellazioni esplicite;
* registra conferma utente e timestamp;
* imposta `user_confirmed`;
* non imposta automaticamente `verified`.

#### Superfici applicative aggiornate

Il resolver è usato in:

* dettaglio prodotto;
* lista prodotti;
* pagina `/warranties`;
* dashboard.

La UI distingue esplicitamente:

* copertura;
* periodo;
* provenienza;
* tipo;
* confidence score tecnico;
* informazioni mancanti;
* stima;
* conferma utente.

### Product lifecycle events

È presente un recorder per gli eventi del ciclo di vita del prodotto.

Sono registrati eventi per:

* prodotto creato da candidato;
* copertura calcolata automaticamente;
* copertura creata manualmente;
* copertura modificata manualmente.

La pagina prodotto mostra una sezione storico.

### Revisioni

È presente la sezione `/reviews`.

La pagina consente di vedere e filtrare:

* candidati da controllare;
* candidati a bassa affidabilità;
* warning Python;
* global facts;
* candidati già revisionati;
* segnali aggregati;
* motivazioni leggibili per l’utente.

Sono presenti azioni rapide per:

* confermare;
* modificare;
* ignorare;
* aprire la revisione documento;
* aprire il prodotto collegato.

Il drawer “Dettaglio conoscenza” mostra:

* candidato;
* origine documento;
* global fact attuale;
* snapshot del global fact;
* feedback;
* analisi Python;
* guardrail identità;
* segnali aggregati;
* metadata tecnici.

La UI distingue warning storici e conoscenza attuale.

Un candidato confermato che ha generato un global fact non deve mostrare `missing_global_facts` come warning attivo.

## Comandi Product Understanding

Seed conoscenza sintetica:

```bash
php artisan product-vault:seed-understanding-knowledge
```

Esecuzione fixture:

```bash
php artisan product-vault:run-understanding-fixtures
```

Test completo Product Understanding:

```bash
php artisan product-vault:test-understanding
```

Audit initial knowledge:

```bash
php artisan product-vault:audit-initial-knowledge
```

Refresh initial knowledge:

```bash
php artisan product-vault:refresh-initial-knowledge
```

Le fixture e i comandi coprono scenari come:

* feedback matcher;
* Python similarity;
* pipeline raw text → righe → candidati;
* EAN inline;
* EAN in colonna;
* seriale in colonna;
* quantità, prezzo e totale coerenti;
* prodotti simili ma diversi;
* varianti OCR e testuali;
* warning e guardrail;
* initial knowledge;
* fuzzy matching;
* global facts.

## Comandi warranty lifecycle e coverage context

```bash
php artisan product-vault:test-warranty-lifecycle
php artisan product-vault:test-warranty-coverage-context
php artisan product-vault:test-manual-warranty-coverage-context
php artisan product-vault:test-product-confirmation-idempotency
```

`test-warranty-lifecycle` copre:

* creazione automatica;
* idempotenza;
* regole categoria;
* prodotto senza `purchase_date`;
* eventi lifecycle automatici e manuali;
* persistenza del contesto stimato.

`test-warranty-coverage-context` copre:

* coperture automatiche;
* coperture manuali;
* garanzie legacy;
* stati della copertura;
* stati temporali;
* informazioni mancanti;
* azioni disponibili;
* comportamento read-only.

`test-manual-warranty-coverage-context` copre:

* creazione manuale;
* aggiornamento del contesto;
* preservazione della provenienza;
* normalizzazione degli input;
* cancellazione esplicita;
* gestione di date e booleani;
* riparazione di metadata legacy o malformati.

`test-product-confirmation-idempotency` verifica che la conferma prodotto non generi duplicazioni o effetti collaterali incoerenti.

## Batch e documenti di test

Sono disponibili documenti smoke e batch controllati.

Tra i set principali:

* documenti `PV_smoke_*`;
* `BATCH01`;
* `BATCH02`.

I batch sono usati per verificare:

* classificazione;
* righe prodotto;
* quantità;
* prezzi;
* totali;
* amount consistency;
* documenti non pertinenti;
* OCR difficile;
* conferme ordine;
* fatture tabellari;
* generazione candidati;
* initial knowledge;
* fuzzy matching;
* global facts.

Gli ID dei documenti locali non sono riferimenti universali del progetto.

## Problemi noti rimandati

### Coverage context non equivale a motore legale

La Fase 4 raccoglie e presenta il contesto necessario per interpretare una copertura, ma non determina automaticamente l’applicabilità giuridica.

Product Vault non applica ancora in modo automatico normative complete basate su:

* paese;
* acquisto B2C o B2B;
* venditore professionale o privato;
* prodotto nuovo, usato o ricondizionato;
* data di consegna;
* condizioni commerciali specifiche.

Queste informazioni sono conservate e rese revisionabili, ma non vengono trasformate in una decisione legale assertiva.

### Stati `declared`, `verified` e `cancelled`

Gli stati sono supportati dal resolver, ma non tutti dispongono ancora di un workflow applicativo completo.

Restano da implementare:

* acquisizione strutturata di una copertura dichiarata;
* verifica con fonte e operatore;
* annullamento tramite azione utente;
* audit completo delle transizioni di stato.

### Metadata invece di colonne dedicate

Il coverage context è attualmente salvato nei metadata.

Questa soluzione è adeguata per la prima iterazione, ma dovrà essere rivalutata se:

* gli stati diventano oggetto di query frequenti;
* aumentano i filtri;
* servono indici database;
* il contratto diventa stabile;
* vengono introdotte transizioni di workflow più complesse.

### Duplicazione delle classi visuali dei badge

Le classi Tailwind per gli stati della copertura e del periodo sono presenti in più superfici.

Per ora la duplicazione è accettata per evitare un refactor prematuro.

In futuro si potrà introdurre:

* presenter condiviso;
* value object;
* componente Blade;
* mappa centralizzata delle classi.

### Python similarity troppo permissiva

L’analyzer Python può proporre match deboli verso prodotti non realmente collegati.

I guardrail riducono il rischio, ma il problema a monte può essere ulteriormente migliorato con:

* soglia minima più rigida;
* token overlap obbligatorio;
* guardrail lessicale;
* soppressione del `best_match` sotto soglia;
* distinzione più netta tra match utile e dato diagnostico.

### Parser order confirmation

Nei documenti e-commerce o nelle conferme ordine, numeri presenti nel nome o modello possono essere interpretati come quantità, prezzo o totale.

Il problema è stato ridotto tramite recovery e controlli, ma resta un’area da monitorare con nuovi batch.

### Merchant parser

In alcuni documenti il venditore può essere ricavato da intestazioni tecniche o sezioni tabellari.

Il parser deve continuare a essere migliorato senza introdurre eccezioni specifiche per singolo file.

### Classification documenti non pertinenti

Un documento non pertinente può essere classificato in modo imperfetto.

Il comportamento minimo richiesto resta:

* nessuna riga prodotto valida;
* nessun candidato utile;
* nessuna creazione automatica di prodotto;
* revisione possibile quando necessaria.

### Duplicazione della logica di presentazione

Parte della logica visuale è ancora duplicata tra:

* dettaglio documento;
* revisioni;
* prodotto;
* lista prodotti;
* pagina garanzie;
* dashboard.

Per l’MVP è preferibile consolidare i flussi prima di introdurre refactor ampi.

## Stato attuale

Alla chiusura dell’implementazione della Fase 4:

* il branch di lavoro è `pv-product-coverage-context`;
* il branch contiene nove commit applicativi prima della documentazione finale;
* il coverage context usa un contratto metadata versionato;
* le coperture automatiche vengono marcate come `estimated`;
* le modifiche manuali vengono marcate come `user_confirmed`;
* la conferma utente non viene presentata come `verified`;
* lo stato temporale è distinto dallo stato della copertura;
* il resolver centralizzato è usato nelle principali superfici applicative;
* i filtri temporali della pagina garanzie sono mutuamente esclusivi;
* dashboard, lista prodotti e dettaglio prodotto usano wording non assertivo;
* i test dedicati risultano verdi nelle verifiche intermedie;
* il branch risulta pulito prima delle modifiche documentali;
* `git diff --check main...HEAD` non ha segnalato errori prima della documentazione.

Restano da eseguire prima dell’integrazione:

1. completare l’aggiornamento della roadmap;
2. eseguire la suite completa di regressione;
3. verificare il diff finale del branch;
4. committare la documentazione;
5. pubblicare il branch;
6. integrare in `main`;
7. rieseguire i controlli essenziali dopo il merge.

La Fase 4 deve essere considerata integrata soltanto dopo la conferma della regressione finale e del merge in `main`.