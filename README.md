# Product Vault

Product Vault è una web app Laravel pensata per aiutare utenti privati, famiglie e, in futuro, negozi o piccoli business a conservare e organizzare documenti legati a prodotti fisici: scontrini, fatture, conferme ordine, certificati di garanzia, manuali, documenti di riparazione e prove d'acquisto.

L'obiettivo dell'MVP non è identificare automaticamente ogni prodotto in modo perfetto. L'obiettivo corretto è creare un flusso affidabile in cui il sistema conserva il documento originale, estrae ciò che riesce a leggere, propone candidati prodotto revisionabili e permette all'utente di confermare o correggere i dati.

## Stato attuale

Il progetto è in fase MVP attiva.

Sono presenti prime versioni funzionanti di:

* autenticazione con Jetstream;
* workspace basati su Jetstream Teams;
* permessi contestuali con Spatie Permission e `team_id`;
* dashboard;
* upload documenti;
* estrazione testo da PDF e immagini;
* OCR/fallback;
* parsing righe documento;
* generazione candidati prodotto;
* Product Understanding;
* feedback workspace;
* global facts;
* analyzer Python con RapidFuzz;
* conferma e ignore candidati;
* creazione prodotti;
* creazione garanzia stimata;
* lifecycle events;
* pagina prodotti;
* pagina garanzie;
* pagina Revisioni;
* comandi di test Product Understanding e warranty lifecycle.

Queste parti non sono definitive, ma rappresentano una prima base MVP funzionante.

## Principi di progetto

### Documento diverso da prodotto

Uno scontrino, una fattura o una conferma ordine non sono il prodotto. Sono prove o documenti collegati.

Il prodotto è una entità separata e può avere più documenti associati.

### Workspace prima dell'utente

Documenti, prodotti e garanzie appartengono a un workspace/team, non direttamente a un singolo utente.

Nel progetto attuale, Jetstream Teams rappresenta il workspace/account.

### Non inventare dati

Se il sistema non riesce a identificare un prodotto con sufficiente affidabilità, deve salvare il documento e chiedere conferma all'utente.

Il sistema non deve creare dati falsi solo per sembrare più automatico.

### Revisione manuale centrale

La revisione non è un fallback secondario. È parte del prodotto.

Product Vault deve aiutare l'utente a confermare più velocemente, non sostituire la conferma quando i dati sono incerti.

### Privacy by design

Ricevute, fatture e documenti prodotto possono contenere dati personali, fiscali o commerciali.

I file devono essere trattati come documenti sensibili e protetti tramite storage privato, policy e autorizzazioni.

## Moduli principali

### Documenti

Il modulo documenti gestisce:

* upload;
* salvataggio file;
* estrazione testo;
* OCR;
* classificazione;
* parsing;
* righe documento;
* candidati prodotto;
* revisione.

### Product Understanding

Il Product Understanding arricchisce i candidati prodotto usando:

* EAN;
* seriali;
* feedback workspace;
* global facts;
* similarità testuale;
* analyzer Python;
* guardrail;
* metadata diagnostici.

Lo scopo è trasformare testo sporco o incompleto in candidati più facili da revisionare.

### Revisioni

La pagina `/reviews` raccoglie i candidati da controllare.

Permette di filtrare candidati pending, candidati a bassa affidabilità, warning Python, global facts e candidati già revisionati.

Include un drawer "Dettaglio conoscenza" per ispezionare global facts, feedback, Python analysis, guardrail e metadata tecnici.

### Prodotti

La conferma di un candidato genera o collega una scheda prodotto.

Il prodotto può essere collegato al documento di origine, a garanzie e a eventi lifecycle.

### Garanzie

Dopo la conferma di un candidato, Product Vault può creare una garanzia stimata usando `WarrantyRule`.

La garanzia automatica è una stima tecnica, non una certezza legale assoluta.

L'utente può modificare o creare manualmente garanzie.

### Lifecycle events

Gli eventi lifecycle registrano passaggi importanti nella vita del prodotto, come:

* prodotto creato da candidato;
* garanzia calcolata automaticamente;
* garanzia creata manualmente;
* garanzia modificata manualmente.

## Stack tecnico

* PHP 8.4
* Laravel 13
* MySQL
* Laravel Jetstream
* Laravel Fortify
* Laravel Sanctum
* Livewire
* Tailwind CSS
* Vite
* Spatie Laravel Permission
* Spatie Laravel Media Library
* Smalot PDF Parser
* Tesseract OCR
* Poppler
* Python
* RapidFuzz
* Laragon per sviluppo locale Windows

## Documentazione interna

La documentazione tecnica viva si trova nella cartella `docs/`.

Documenti principali:

* `docs/00-project-status.md`
* `docs/01-product-understanding.md`
* `docs/02-warranty-lifecycle.md`
* `docs/03-reviews-workflow.md`
* `docs/04-tests-and-fixtures.md`
* `docs/05-technical-backlog.md`

Il README deve restare una panoramica breve. I dettagli tecnici vanno mantenuti nei documenti dedicati.

## Comandi principali

Pulizia cache Laravel:

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

Seed conoscenza sintetica Product Understanding:

```
php artisan product-vault:seed-understanding-knowledge
```

Esecuzione fixture Product Understanding:

```
php artisan product-vault:run-understanding-fixtures
```

Tinker:

```
php artisan tinker
```

## Installazione locale

Clonare la repository:

```
git clone https://github.com/Nastu94/product-vault.git
cd product-vault
```

Installare dipendenze PHP:

```
composer install
```

Installare dipendenze frontend:

```
npm install
```

Copiare il file ambiente:

```
cp .env.example .env
```

Su Windows si può copiare manualmente `.env.example` in `.env`.

Generare la chiave applicativa:

```
php artisan key:generate
```

Configurare database e variabili nel file `.env`.

Eseguire le migration:

```
php artisan migrate
```

Eseguire i seeder necessari:

```
php artisan db:seed
```

Avviare il server locale:

```
php artisan serve
```

Avviare Vite:

```
npm run dev
```

## Tool esterni usati in sviluppo

Product Vault usa strumenti locali per estrazione testo e OCR.

Componenti rilevanti:

* Poppler, per conversione/lettura PDF;
* Tesseract OCR, per immagini e scansioni;
* Python virtual environment per tool Product Understanding;
* RapidFuzz per similarità testuale.

I percorsi locali e virtual environment non devono essere committati.

## Regole operative

Lo sviluppo procede a micro-patch.

Dopo ogni patch significativa:

```
git status

php artisan optimize:clear

php artisan product-vault:test-understanding

php artisan product-vault:test-warranty-lifecycle
```

Committare solo file intenzionali.

Non committare:

* `.env`;
* file caricati manualmente;
* file generati in `storage`;
* virtual environment Python;
* output OCR temporanei;
* file temporanei di debug;
* dump locali;
* documenti personali o sensibili.

## Backlog sintetico

Priorità principali dopo la documentazione:

1. progettare knowledge base iniziale;
2. correggere Python similarity troppo permissiva;
3. introdurre guardrail lessicali più forti;
4. migliorare parser `order_confirmation`;
5. migliorare merchant parser;
6. migliorare classificazione documenti non pertinenti;
7. ridurre duplicazione logica candidato tra dettaglio documento e Revisioni.

Il backlog completo è in:

```
docs/05-technical-backlog.md
```

## Fuori ambito MVP attuale

Non sono parte del consolidamento immediato:

* marketplace;
* vendita usato;
* pagamenti;
* integrazioni Amazon/eBay/Gmail;
* app mobile;
* AI obbligatoria;
* reclami automatici;
* B2B avanzato;
* notifiche complesse;
* backoffice completo.

Queste aree potranno arrivare dopo il consolidamento dei flussi core: documento, prodotto, garanzia, revisione e knowledge base.

## Licenza

Progetto privato in sviluppo.