# Product Vault - Initial knowledge base

Questo documento definisce la strategia per la knowledge base iniziale di Product Vault.

La knowledge base iniziale è il sistema che permette a un database pulito di partire con una base minima di conoscenza utile, senza aspettare che l'utente carichi molti documenti e confermi manualmente decine di candidati.

Questo blocco è strategico perché Product Vault non deve essere utile solo dopo molto uso. Deve offrire valore già dai primi caricamenti, mantenendo però un principio fondamentale: non inventare dati e non creare match falsi.

## Principio guida

La knowledge base iniziale deve essere piccola, controllata, idempotente e testabile.

Non deve essere un catalogo prodotti enorme.

Un catalogo grande ma sporco peggiorerebbe il Product Understanding, perché aumenterebbe falsi match, canonical name errati e suggerimenti poco affidabili.

La prima versione deve quindi concentrarsi su:

* categorie iniziali;
* line type;
* brand comuni;
* alias controllati;
* pattern lessicali;
* regole di esclusione;
* segnali utili per distinguere prodotto, accessorio, servizio, garanzia, sconto, tassa e documento non pertinente.

Solo in modo molto selettivo potrà includere prodotti o EAN reali, e solo quando la fonte è affidabile e il dato è utile.

## Problema da risolvere

Senza una knowledge base iniziale, Product Vault impara quasi solo da:

* documenti caricati;
* candidati generati;
* conferme utente;
* ignore utente;
* global facts creati progressivamente.

Questo approccio è corretto nel lungo periodo, ma debole al primo utilizzo.

Rischi:

* primi documenti poco valorizzati;
* troppa revisione manuale;
* pochi badge conoscenza;
* pochi suggerimenti utili;
* Product Understanding percepito come vuoto;
* servizio gratuito poco convincente.

La knowledge base iniziale deve ridurre questo problema senza introdurre dati falsi.

## Cosa non deve diventare

La knowledge base iniziale non deve diventare:

* un catalogo globale prodotti non verificato;
* una lista enorme di EAN copiati senza fonte;
* una scorciatoia per confermare prodotti automaticamente;
* un sostituto della revisione utente;
* un insieme di dati sintetici mascherati da dati reali;
* un dump difficile da aggiornare;
* una fonte di match deboli;
* un meccanismo che sporca global facts degli utenti.

## Differenza tra seed tecnico, fixture e knowledge base

È importante separare tre concetti.

### Seed tecnico

Il seed tecnico serve a preparare il database per far funzionare l'applicazione.

Esempi:

* ruoli;
* permessi;
* piani base;
* lookup;
* tipi documento;
* tipi riga;
* tipi garanzia;
* regole garanzia;
* utente demo locale, se previsto.

Il seed tecnico non è conoscenza prodotto.

### Fixture

Le fixture servono a testare scenari controllati.

Esempi:

* candidato con EAN inline;
* candidato con EAN in colonna;
* prodotto simile ma diverso;
* variante OCR;
* match Python debole;
* documento non pertinente;
* order confirmation problematica.

Le fixture possono usare dati sintetici, perché il loro scopo è testare logica.

Non devono essere usate come conoscenza reale di produzione.

### Knowledge base iniziale

La knowledge base iniziale serve a migliorare il comportamento del sistema su database pulito.

Può contenere dati generali e controllati, come:

* categorie supportate;
* brand comuni;
* alias brand;
* pattern prodotto;
* pattern accessorio;
* pattern sconto;
* pattern servizio;
* pattern garanzia;
* regole lessicali;
* esclusioni;
* eventuali prodotti canonici selezionati e verificati.

A differenza delle fixture, la knowledge base iniziale può influenzare l'esperienza reale dell'utente. Per questo deve essere più prudente.

## Tipi di conoscenza ammessi

### Categorie prodotto

Le categorie aiutano Product Understanding e warranty lifecycle.

Esempi iniziali:

* notebook;
* smartphone;
* tablet;
* monitor;
* cuffie;
* tastiere;
* mouse;
* docking station;
* stampanti;
* elettrodomestici;
* console;
* accessori elettronici;
* componenti PC;
* strumenti domestici.

Le categorie devono restare abbastanza generali nella prima versione.

### Line type

I line type aiutano a classificare le righe documento.

Esempi:

* durable product;
* accessory;
* consumable;
* service;
* warranty;
* discount;
* shipping;
* tax;
* payment;
* total;
* merchant info;
* unknown.

Questi dati sono più sicuri dei prodotti specifici, perché descrivono il tipo di riga e non identificano un prodotto reale.

### Brand comuni

I brand aiutano a riconoscere prodotti e modelli.

Esempi:

* Apple;
* Samsung;
* Lenovo;
* HP;
* Dell;
* ASUS;
* Acer;
* Sony;
* LG;
* Philips;
* Logitech;
* Microsoft;
* Nintendo;
* Bosch;
* Dyson.

Il brand da solo non identifica un prodotto. Deve essere trattato come segnale debole o medio, non come prova.

### Alias brand

Gli alias aiutano a gestire varianti testuali e OCR.

Esempi concettuali:

* `hewlett packard` -> `HP`;
* `logi` -> `Logitech`;
* `lenovo thinkpad` -> brand `Lenovo`, linea `ThinkPad`;
* `samsung electronics` -> `Samsung`.

Gli alias devono essere controllati. Alias troppo generici possono creare falsi positivi.

### Pattern prodotto

Pattern testuali utili per capire che una riga sembra un prodotto.

Esempi:

* presenza brand + modello;
* presenza EAN;
* presenza seriale;
* parole come notebook, monitor, cuffie, tastiera, mouse, smartphone;
* descrizioni con capacità, colore, generazione o formato.

I pattern prodotto non devono confermare automaticamente. Devono solo aumentare confidence o suggerire categoria.

### Pattern accessorio

Esempi:

* custodia;
* cover;
* cavo;
* caricatore;
* adattatore;
* docking station;
* supporto;
* hub USB;
* pellicola;
* mouse;
* tastiera.

Gli accessori possono essere prodotti registrabili, ma spesso hanno priorità diversa rispetto a beni principali.

### Pattern servizio

Esempi:

* installazione;
* configurazione;
* assistenza;
* montaggio;
* spedizione;
* consegna;
* trasporto;
* intervento tecnico.

Questi pattern aiutano a evitare candidati prodotto falsi.

### Pattern garanzia

Esempi:

* garanzia estesa;
* protezione;
* estensione garanzia;
* supporto premium;
* applecare;
* assicurazione prodotto.

Queste righe non sono il prodotto principale, ma possono essere importanti per warranty lifecycle.

Nella prima versione non devono sostituire automaticamente la garanzia legale stimata.

### Pattern sconto

Esempi:

* sconto;
* coupon;
* promo;
* cashback;
* buono;
* voucher;
* riduzione;
* arrotondamento.

Questi pattern aiutano a evitare candidati non prodotto.

### Pattern tassa/pagamento/totale

Esempi:

* IVA;
* imponibile;
* totale;
* subtotale;
* pagamento;
* bancomat;
* carta;
* contanti;
* resto.

Questi pattern riducono falsi candidati generati da righe contabili.

### Regole di esclusione

Le esclusioni sono fondamentali quanto i segnali positivi.

Esempi:

* non creare candidato da righe di pagamento;
* non creare candidato da righe totale;
* non creare candidato da righe IVA;
* non creare candidato da intestazioni tabellari;
* non creare candidato da sezioni come "Righe documento";
* non trattare codici ordine come EAN;
* non trattare numeri modello come quantità/prezzo senza contesto.

## Tipi di conoscenza da evitare nella prima versione

### Grande catalogo prodotti

Da evitare.

Un catalogo ampio richiede:

* fonti affidabili;
* aggiornamento;
* deduplicazione;
* gestione varianti;
* licenza dati;
* normalizzazione;
* controllo falsi match.

Non è necessario per il primo MVP.

### EAN non verificati

Da evitare.

Un EAN errato è molto pericoloso, perché può diventare un segnale forte sbagliato.

Gli EAN vanno inseriti solo quando:

* sono affidabili;
* servono a fixture o test controllati;
* sono marcati come dati demo/sintetici se non reali;
* non vengono confusi con conoscenza reale globale.

### Dati sintetici in produzione

Da evitare.

I dati sintetici sono utili per test, ma non devono apparire come conoscenza reale.

Se vengono usati in locale, devono essere chiaramente separati.

### Alias troppo generici

Da evitare.

Esempi rischiosi:

* `pro` come alias;
* `max` come alias;
* `plus` come alias;
* `mini` come alias;
* `smart` come alias.

Questi token sono troppo comuni e possono creare match falsi.

## Perimetro MVP della knowledge base

La prima versione deve includere solo dati ad alto valore e basso rischio.

Perimetro consigliato:

* categorie base;
* line type;
* brand comuni;
* alias brand prudenti;
* pattern positivi prodotto;
* pattern negativi;
* pattern servizio;
* pattern garanzia;
* pattern sconto;
* pattern pagamento/totale;
* regole lessicali minime per evitare falsi candidati;
* metadata di versione del knowledge pack.

Fuori perimetro prima versione:

* catalogo prodotti completo;
* import massivo EAN;
* scraping dati esterni;
* ranking automatico avanzato;
* knowledge pack per paese complesso;
* backoffice globale;
* sincronizzazione remota.

## Formato dati consigliato

La knowledge base iniziale dovrebbe essere versionata in repository.

Formato possibile:

```
data/product_vault/knowledge/v1/categories.php

data/product_vault/knowledge/v1/brands.php

data/product_vault/knowledge/v1/brand_aliases.php

data/product_vault/knowledge/v1/line_patterns.php

data/product_vault/knowledge/v1/exclusion_patterns.php

data/product_vault/knowledge/v1/metadata.php
```

In alternativa, per una prima patch più semplice, si può partire da:

```
config/product_vault_knowledge.php
```

La scelta va fatta prima dell'implementazione.

## Opzione A: file in `data/`

Vantaggi:

* separa dati da configurazione;
* più adatto a knowledge pack versionati;
* permette più file piccoli;
* più facile da estendere;
* più chiaro per import futuri.

Svantaggi:

* serve creare loader dedicato;
* serve decidere struttura directory;
* richiede un po' più di codice.

## Opzione B: config Laravel

Vantaggi:

* semplice;
* caricato facilmente;
* meno codice iniziale;
* adatto a prototipo piccolo.

Svantaggi:

* rischia di mischiare configurazione e dati;
* meno adatto a versionamento dati;
* può diventare grande e poco leggibile;
* meno naturale per import/export futuri.

## Decisione proposta

Per Product Vault è preferibile usare file versionati in `data/product_vault/knowledge/v1/`.

Motivo:

La knowledge base è un asset del prodotto, non semplice configurazione Laravel.

La config può contenere parametri di import, soglie o feature flag, ma i dati della knowledge base dovrebbero stare in file dedicati.

## Idempotenza

L'import della knowledge base deve essere idempotente.

Eseguire il comando più volte non deve creare duplicati.

Comportamento atteso:

* se una categoria esiste già, viene aggiornata o lasciata invariata;
* se un brand esiste già, viene aggiornato o lasciato invariato;
* se un alias esiste già, non viene duplicato;
* se una regola esiste già, non viene duplicata;
* i dati utente non vengono sovrascritti;
* i global facts derivati dall'uso utente non vengono cancellati.

## Protezione dati utente

La knowledge base iniziale non deve sovrascrivere conoscenza generata dagli utenti.

Regola:

* dati di sistema e dati utente devono essere distinguibili;
* ogni record importato deve avere fonte o namespace;
* eventuali aggiornamenti devono riguardare solo record gestiti dal knowledge pack;
* non cancellare global facts generati da conferme utente;
* non cambiare canonical name scelti da feedback reale senza processo esplicito.

## Source e namespace

Ogni dato importato dovrebbe avere una fonte.

Esempi concettuali:

* `source = initial_knowledge_pack`;
* `source_version = v1`;
* `managed_by = system`;
* `is_system = true`.

Se le tabelle attuali non hanno ancora questi campi, la prima implementazione può partire in modo più semplice, ma la direzione deve restare questa.

## Versionamento

Ogni knowledge pack deve avere una versione.

Esempio:

* `initial_knowledge_pack_v1`.

La versione serve per:

* capire cosa è stato importato;
* evitare import parziali;
* aggiornare dati in futuro;
* scrivere test;
* diagnosticare comportamenti.

Possibile file metadata:

```
data/product_vault/knowledge/v1/metadata.php
```

Contenuto concettuale:

* version;
* description;
* created_at;
* intended_environment;
* data_types;
* notes.

## Comando artisan futuro

Nome proposto:

```
php artisan product-vault:seed-initial-knowledge
```

Responsabilità:

* leggere i file knowledge pack;
* validare struttura;
* importare dati idempotenti;
* mostrare riepilogo;
* segnalare warning;
* non toccare dati utente;
* uscire con errore se i file sono invalidi.

Output desiderato:

* categorie create/aggiornate;
* brand creati/aggiornati;
* alias creati/aggiornati;
* pattern creati/aggiornati;
* warning;
* versione importata.

## Comando di verifica futuro

Nome possibile:

```
php artisan product-vault:test-initial-knowledge
```

Responsabilità:

* verificare che il knowledge pack sia leggibile;
* verificare idempotenza;
* verificare assenza di duplicati;
* verificare pattern critici;
* verificare che non vengano creati global facts utente;
* verificare che i dati di test non finiscano nella knowledge base reale.

Questo comando può essere aggiunto dopo la prima implementazione.

## Relazione con Product Understanding

La knowledge base iniziale deve aiutare Product Understanding a:

* riconoscere brand;
* suggerire categorie;
* distinguere prodotti da righe non prodotto;
* migliorare review hint;
* ridurre candidati falsi;
* rafforzare guardrail;
* migliorare token overlap;
* ridurre match deboli.

Non deve sostituire:

* feedback workspace;
* global facts;
* conferma utente;
* analyzer Python;
* guardrail.

## Relazione con Python similarity

La knowledge base iniziale può fornire dati utili all'analyzer Python.

Esempi:

* brand conosciuti;
* alias brand;
* token esclusi;
* parole comuni da penalizzare;
* pattern modello;
* categorie incompatibili.

Questo è importante per correggere il problema dei match deboli.

La direzione corretta è usare la knowledge base anche per migliorare la qualità del matching, non solo per aggiungere suggerimenti.

## Relazione con global facts

I global facts nascono soprattutto dall'uso del sistema e da identificatori forti, come EAN.

La knowledge base iniziale non deve creare global facts indistinguibili da quelli generati dagli utenti.

Possibili strategie:

* non creare global facts nella prima versione;
* creare solo dati di supporto come categorie, brand, alias e pattern;
* creare global facts solo in ambiente demo/test;
* marcare ogni dato system con source chiara.

Per MVP, la scelta più prudente è non creare global facts reali dalla knowledge base iniziale, almeno nella prima patch.

## Relazione con fixture

Le fixture possono usare dati sintetici.

La knowledge base iniziale deve usare dati controllati e pensati per il comportamento reale.

Regola:

* fixture e knowledge pack devono stare separati;
* i comandi devono essere diversi;
* i dati devono essere distinguibili;
* i test devono impedire contaminazione.

## Relazione con merchant parser

La knowledge base iniziale può aiutare anche il merchant parser, ma non deve diventare subito un database merchant completo.

Possibili dati futuri:

* parole da escludere come merchant;
* pattern intestazioni tecniche;
* alias merchant noti;
* segnali P.IVA;
* segnali dominio.

Per prima versione, conviene includere solo esclusioni generiche come:

* `righe documento`;
* `descrizione`;
* `quantità`;
* `prezzo`;
* `totale`;
* `iva`;
* `codice articolo`.

## Relazione con classification

La knowledge base può aiutare la classificazione documenti.

Esempi:

* pattern receipt;
* pattern invoice;
* pattern order confirmation;
* pattern warranty certificate;
* pattern manual;
* pattern repair document;
* pattern not relevant.

Per prima versione, meglio non cambiare subito classifier. Prima definire dati e import.

## Qualità dei dati

Ogni elemento della knowledge base deve rispettare criteri minimi.

Criteri:

* chiaro;
* non ambiguo;
* utile;
* basso rischio di falso positivo;
* testabile;
* aggiornabile;
* non sensibile;
* non dipendente da dati personali;
* non legato a ID locali.

Un dato dubbio non va inserito.

## Esempi di dati sicuri

Dati relativamente sicuri:

* categorie generiche;
* brand noti;
* alias brand molto chiari;
* parole negative come `totale`, `iva`, `sconto`;
* pattern servizio come `spedizione`, `installazione`;
* pattern garanzia come `garanzia estesa`.

Questi dati raramente identificano un prodotto da soli, ma aiutano a leggere meglio le righe.

## Esempi di dati rischiosi

Dati rischiosi:

* EAN non verificati;
* prodotti specifici senza fonte;
* alias ambigui;
* abbreviazioni troppo brevi;
* parole comuni;
* modelli troppo generici;
* dati presi da documenti locali smoke;
* dati sintetici usati come se fossero reali.

Questi dati possono peggiorare Product Understanding.

## Prima implementazione consigliata

La prima implementazione dovrebbe essere limitata.

Step 1:

* creare file dati versionati;
* inserire categorie base;
* inserire brand base;
* inserire alias brand prudenti;
* inserire pattern negativi;
* inserire pattern line type;
* non creare global facts reali.

Step 2:

* creare comando import idempotente;
* stampare riepilogo;
* non toccare dati utente.

Step 3:

* aggiungere test idempotenza;
* verificare che non vengano creati duplicati.

Step 4:

* usare i dati nel Product Understanding solo dove serve;
* non cambiare subito tutto il matching.

## Patch da evitare nella prima implementazione

Non fare nella prima patch:

* grande refactor Product Understanding;
* modifica analyzer Python;
* import prodotti reali;
* import EAN;
* modifica classifier;
* modifica merchant parser;
* nuova UI;
* backoffice;
* tabelle complesse non necessarie;
* automazioni di conferma.

Prima creare dati e comando in modo sicuro.

## Possibili tabelle coinvolte

Dipende dallo schema attuale.

Possibili entità esistenti o future:

* categories;
* brands;
* merchant aliases;
* global product facts;
* line type lookup;
* pattern table futura;
* config/data file letti a runtime.

Prima dell'implementazione bisogna verificare lo schema reale, non assumere tabelle inesistenti.

## Strategia minima senza nuove tabelle

Se non esistono tabelle adatte per pattern e alias, si può partire con file dati versionati e usarli a runtime o in service dedicati.

Vantaggi:

* nessuna migration iniziale;
* patch piccola;
* basso rischio;
* facile da testare.

Svantaggi:

* meno interrogabile da database;
* backoffice futuro da progettare;
* aggiornamento solo via deploy.

Questa strategia può essere accettabile per MVP.

## Strategia con nuove tabelle

Se si decide di rendere la knowledge base interrogabile, servono migration dedicate.

Possibili tabelle future:

* `knowledge_pack_imports`;
* `knowledge_patterns`;
* `brand_aliases`;
* `category_aliases`;
* `line_type_patterns`.

Vantaggi:

* dati interrogabili;
* idempotenza tracciabile;
* backoffice più facile in futuro.

Svantaggi:

* più codice;
* più rischio;
* maggiore complessità ora.

Per MVP conviene evitare nuove tabelle finché non servono davvero.

## Verifiche richieste

Dopo ogni patch su knowledge base:

```
php artisan optimize:clear

php artisan product-vault:test-understanding

php artisan product-vault:test-warranty-lifecycle
```

Quando esisterà il comando dedicato:

```
php artisan product-vault:test-initial-knowledge
```

## Backlog knowledge base

### P0 - Progettazione e base

* Documentare formato e confini.
* Decidere `data/` vs `config`.
* Creare primo knowledge pack minimo.
* Creare import idempotente.
* Evitare global facts reali nella prima versione.
* Aggiungere verifica idempotenza.

### P1 - Integrazione Product Understanding

* Usare brand e alias come segnali.
* Usare pattern negativi per ridurre candidati falsi.
* Usare token comuni per migliorare Python similarity.
* Usare categorie come vincoli leggeri.
* Aggiungere fixture dedicate.

### P2 - Evoluzione

* Backoffice knowledge base.
* Versionamento avanzato.
* Import/export.
* Knowledge pack per paese.
* Knowledge pack per categorie verticali.
* Validazione qualità.
* Metriche falsi match.
* Aggiornamenti controllati.

## Decisioni aperte

### Dove mettere i file dati?

Opzioni:

* `data/product_vault/knowledge/v1/`
* `config/product_vault_knowledge.php`

Decisione proposta:

* usare `data/product_vault/knowledge/v1/`.

### Creare nuove tabelle subito?

Decisione proposta:

* no, salvo necessità emersa dallo schema attuale.

### Importare EAN reali subito?

Decisione proposta:

* no.

### Creare global facts da knowledge base iniziale?

Decisione proposta:

* no nella prima versione.

### Usare la knowledge base per Python similarity?

Decisione proposta:

* sì, ma in una fase successiva, dopo aver corretto i match deboli con fixture dedicate.

## Criterio di successo

La prima knowledge base iniziale ha successo se:

* è importabile su database pulito;
* non crea duplicati;
* non tocca dati utente;
* migliora segnali di base;
* riduce rumore;
* non aumenta falsi match;
* resta piccola e leggibile;
* può essere testata;
* può essere estesa senza refactor.

## Decisione strategica

La knowledge base iniziale è un asset del prodotto.

Deve nascere come fondazione controllata, non come raccolta casuale di dati.

La direzione corretta è partire da conoscenza strutturale e lessicale, non da un catalogo prodotti.

Prima bisogna insegnare al sistema a capire meglio che tipo di riga sta leggendo. Solo dopo ha senso aggiungere conoscenza specifica su prodotti, EAN e varianti.