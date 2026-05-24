# Product Vault

Product Vault è una web app Laravel pensata per aiutare utenti privati, famiglie e, in futuro, negozi o piccoli business a conservare e organizzare documenti legati a prodotti fisici: scontrini, fatture, certificati di garanzia, manuali, documenti di riparazione e prove d'acquisto.

L'obiettivo dell'MVP non è identificare automaticamente ogni prodotto in modo perfetto, ma creare un flusso affidabile in cui il sistema conserva il documento originale, prova a estrarre i dati utili e permette all'utente di revisionare e completare le informazioni mancanti.

## Stato del progetto

Progetto in fase iniziale di sviluppo.

Funzionalità già impostate:

- Laravel installato
- Laravel Jetstream con Livewire
- Team Jetstream usati come workspace/account
- Autenticazione utente
- Ruoli e permessi con Spatie Permission
- Permessi contestuali rispetto al team/workspace corrente
- Middleware per collegare il team Jetstream corrente al contesto Spatie
- Model `Document`
- Policy `DocumentPolicy`
- Integrazione iniziale con Spatie Media Library sul model `Document`
- Repository GitHub collegata

Funzionalità non ancora completate:

- Upload documenti tramite Livewire
- Lista documenti
- Dettaglio documento
- Estrazione testo da PDF
- OCR immagini
- Classificazione documento
- Parsing dati
- Revisione manuale post-upload
- Creazione prodotti
- Gestione garanzie
- Barcode
- Notifiche
- Audit log completo

## Visione MVP

Product Vault vuole trasformare documenti passivi in schede prodotto revisionabili.

Esempio di flusso previsto:

1. L'utente carica un documento.
2. Il sistema salva il file originale.
3. Il sistema prova a estrarre testo e metadati.
4. Il documento viene classificato.
5. Se possibile, viene proposta una bozza prodotto.
6. L'utente revisiona e conferma i dati.
7. Il prodotto può essere collegato a garanzie, documenti e storico eventi.

## Principi di progetto

### Documento diverso da prodotto

Uno scontrino o una fattura non sono il prodotto, ma una prova o un documento collegato.
Il prodotto è una entità separata e può avere più documenti associati.

### Workspace prima dell'utente

Documenti e prodotti appartengono a un workspace/account, non direttamente a un singolo utente.

Nel progetto attuale, il team Jetstream rappresenta il workspace/account.

### Non inventare dati

Se il sistema non riesce a identificare un prodotto con sufficiente affidabilità, deve salvare il documento e chiedere conferma all'utente, non creare dati falsi.

### Revisione manuale centrale

L'automazione deve aiutare l'utente, non sostituirlo completamente.
La schermata di revisione sarà una parte centrale dell'MVP.

### Privacy by design

I documenti possono contenere dati personali, fiscali o commerciali.
Per questo motivo l'accesso ai file dovrà essere protetto tramite autorizzazioni e policy.

## Stack tecnico

- PHP 8.4
- Laravel 13
- MySQL
- Laravel Jetstream
- Laravel Fortify
- Laravel Sanctum
- Livewire
- Spatie Laravel Permission
- Spatie Laravel Media Library
- Smalot PDF Parser
- Intervention Image
- Tailwind CSS
- Vite
- Laragon per sviluppo locale

## Architettura applicativa

Il progetto seguirà una struttura orientata a componenti Livewire, Action, Service e Job.

### Controller

I controller saranno usati il meno possibile e solo quando realmente utili, ad esempio per rotte specifiche, download protetti o endpoint particolari.

### Livewire Components

I componenti Livewire gestiranno la UI e l'interazione utente.

Esempi previsti:

- lista documenti
- upload documento
- dettaglio documento
- revisione documento
- gestione prodotti
- gestione garanzie

### Actions

Le Action conterranno operazioni applicative specifiche.

Esempi:

- salvare un documento caricato
- creare una bozza prodotto
- confermare un prodotto
- associare un documento a un prodotto

### Services

I Service coordineranno logiche più ampie o riutilizzabili.

Esempi:

- pipeline di estrazione testo
- classificazione documento
- parsing dati
- calcolo affidabilità
- gestione garanzie

### Jobs

I Job gestiranno operazioni asincrone o potenzialmente lente.

Esempi:

- elaborazione documento
- OCR
- parsing
- generazione anteprime
- notifiche

### Policies

Le Policy Laravel proteggeranno l'accesso alle risorse sensibili.

Un utente potrà accedere solo ai documenti appartenenti al proprio workspace/team corrente.

## Workspace e permessi

Il progetto usa Jetstream Teams come base per il concetto di workspace/account.

Relazione logica attuale:

```text
User
 -> currentTeam
 -> Documents
 -> Products
 -> Warranties
```

Spatie Permission viene usato con supporto teams abilitato.

Ruolo iniziale:

```text
account_owner
```

Permessi iniziali:

```text
documents.view
documents.upload
```

Permessi futuri previsti:

```text
documents.update
documents.delete
documents.review

products.view
products.create
products.update
products.delete

warranties.view
warranties.create
warranties.update
warranties.delete

barcodes.create
barcodes.delete

account.members.view
account.members.invite
account.members.remove
account.settings.update
```

## Model principali previsti

### Già avviati

- `User`
- `Team`
- `Document`

### Da implementare

- `Product`
- `Warranty`
- `DocumentLine`
- `DocumentTextExtraction`
- `DocumentClassification`
- `ProductIdentificationCandidate`
- `BarcodeScan`
- `AuditLog`

## Document model

Il model `Document` rappresenta un documento caricato dall'utente.

Tipologie previste:

- scontrino
- fattura
- certificato di garanzia
- manuale
- documento di riparazione
- foto prodotto
- foto seriale
- documento sconosciuto
- documento non supportato

Stati previsti:

- uploaded
- processing
- text_extracted
- classified
- parsed
- needs_review
- low_confidence
- linked_to_product
- unsupported
- failed

## Roadmap MVP

### 1. Setup base

- Laravel
- Jetstream
- Livewire
- GitHub
- Spatie Permission
- Team/workspace

### 2. Area documenti

- lista documenti
- upload documento
- salvataggio file originale
- storage privato
- policy di accesso
- dettaglio documento

### 3. Processing documenti

- job asincrono
- estrazione testo PDF
- fallback parser
- OCR immagini
- salvataggio testo grezzo

### 4. Classificazione e parsing

- classificazione documento
- parsing venditore
- parsing data
- parsing totale
- parsing righe candidate

### 5. Revisione manuale

- schermata di revisione
- correzione dati
- conferma documento
- creazione bozza prodotto

### 6. Prodotti e garanzie

- model prodotto
- collegamento documenti/prodotti
- garanzie stimate
- scadenze
- affidabilità dati

### 7. Funzioni evolutive

- barcode
- notifiche
- audit log
- backoffice
- supporto multi-account avanzato
- supporto negozi

## Installazione locale

Clonare la repository:

```bash
git clone https://github.com/Nastu94/product-vault.git
cd product-vault
```

Installare dipendenze PHP:

```bash
composer install
```

Installare dipendenze frontend:

```bash
npm install
```

Copiare il file ambiente:

```bash
cp .env.example .env
```

Generare la chiave applicativa:

```bash
php artisan key:generate
```

Configurare database e variabili nel file `.env`.

Eseguire le migration:

```bash
php artisan migrate
```

Eseguire i seeder:

```bash
php artisan db:seed --class=PermissionSeeder
```

Avviare il server locale:

```bash
php artisan serve
```

Avviare Vite:

```bash
npm run dev
```

## Comandi utili

Pulizia cache:

```bash
php artisan optimize:clear
```

Tinker:

```bash
php artisan tinker
```

Esecuzione migration:

```bash
php artisan migrate
```

Rollback ultimo batch migration:

```bash
php artisan migrate:rollback
```

Build frontend:

```bash
npm run build
```

## Note di sviluppo

Il progetto è in fase attiva di costruzione.

Per evitare complessità premature:

- non verrà aggiunto OCR finché il flusso upload non sarà stabile;
- non verranno creati controller pieni di logica;
- non verranno implementate funzioni marketplace nell'MVP;
- non verranno automatizzate decisioni incerte senza revisione utente;
- non verranno salvati documenti sensibili in cartelle pubbliche senza controllo autorizzativo.

## Licenza

Progetto privato in sviluppo.