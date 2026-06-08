# Product Vault - Stato progetto

Questo documento fotografa lo stato tecnico e funzionale attuale di Product Vault dopo il completamento della prima versione della sezione Revisioni.

Il progetto è ancora in fase MVP, ma non è più nella fase iniziale: upload, processing documenti, Product Understanding, prodotti, garanzie, lifecycle events e Revisioni hanno già una prima versione funzionante.

## Contesto tecnico

* Repository: `Nastu94/product-vault`
* Ambiente locale: Windows + Laragon
* Stack backend: Laravel 13.11.2, PHP 8.4.7
* Stack frontend: Livewire, Tailwind, Vite
* Autenticazione/workspace: Laravel Jetstream Teams
* Permessi: Spatie Permission con `team_id`
* Branch consolidato: `main`
* Branch ultimo blocco completato: `pv-understanding-scenarios`

## Regole operative di sviluppo

Lo sviluppo procede a micro-patch.

Ogni patch deve essere piccola, verificabile e possibilmente isolata su un solo tema. Evitare refactor grandi se non sono necessari per consolidare l'MVP.

Quando viene aggiunta logica PHP non banale, il codice deve contenere commenti chiari, soprattutto in service, metodi applicativi, comandi artisan e punti decisionali della pipeline.

Non vanno committati:

* `.env`
* file caricati manualmente
* file generati in `storage`
* virtual environment Python
* output OCR temporanei
* file temporanei di debug
* documenti locali non pensati per la repository

## Comandi di verifica principali

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

Questi due comandi sono attualmente i test principali per validare le parti più delicate dell'MVP.

## Moduli completati come prima versione MVP

### Autenticazione, workspace e permessi

Il progetto usa Jetstream Teams come workspace/account attivo.

Spatie Permission è configurato con contesto `team_id`, in modo che ruoli e permessi siano valutati rispetto al team corrente.

Il ruolo iniziale principale è `account_owner`.

### Dashboard

La dashboard mostra riepiloghi operativi e link verso le aree principali.

È stata aggiornata per includere anche elementi legati a garanzie e revisioni.

### Documenti

È presente il modulo documenti con:

* upload;
* salvataggio file;
* estrazione testo;
* OCR/fallback;
* classificazione;
* parsing;
* righe documento;
* candidati prodotto;
* dettaglio documento;
* azioni di revisione.

Il flusso documento rimane volutamente revisionabile: il sistema non deve creare dati affidabili senza conferma quando l'identificazione è incerta.

### Product Understanding

È stata introdotta una prima knowledge base operativa basata su:

* feedback workspace;
* global facts;
* EAN;
* seriali;
* similarità testuale;
* analyzer Python con RapidFuzz;
* metadata diagnostici salvati sui candidati;
* guardrail per conflitti modello, differenze specifiche, varianti OCR e match deboli.

Esistono comandi dedicati per generare conoscenza sintetica e testare scenari controllati.

### Prodotti

La conferma di un candidato genera un prodotto.

Il prodotto viene collegato al documento di origine e conserva informazioni come nome, modello, EAN, seriale, prezzo e data di acquisto quando disponibili.

### Warranty lifecycle

È stata completata una prima versione del ciclo garanzie:

* creazione automatica di una garanzia stimata dopo conferma candidato;
* uso di `WarrantyRule`;
* default legale italiano a 24 mesi;
* idempotenza nella creazione automatica;
* modifica manuale garanzia;
* creazione manuale se assente;
* riepilogo in lista prodotti;
* pagina dedicata `/warranties`;
* card e riepiloghi in dashboard.

La garanzia calcolata resta una stima tecnica, non una dichiarazione legale assoluta.

### Product lifecycle events

È presente un recorder per eventi di ciclo vita prodotto.

Sono registrati eventi per:

* prodotto creato da candidato;
* garanzia calcolata automaticamente;
* garanzia creata manualmente;
* garanzia modificata manualmente.

La pagina prodotto mostra una sezione storico.

### Revisioni

È stata completata la prima versione della sezione `/reviews`.

La pagina consente di vedere e filtrare candidati da controllare, candidati a bassa affidabilità, warning Python, global facts e candidati già revisionati.

Sono presenti azioni rapide per confermare, ignorare, aprire la revisione documento o aprire il prodotto collegato.

È stato aggiunto il drawer "Dettaglio conoscenza", che mostra:

* candidato;
* origine documento;
* global fact attuale;
* snapshot global fact salvato nel candidato;
* feedback;
* analisi Python;
* guardrail identità;
* segnali aggregati;
* metadata tecnici.

È stata corretta la distinzione tra warning storici e conoscenza attuale: un candidato confermato che ha generato un global fact non deve più mostrare `missing_global_facts` come warning attivo.

## Comandi Product Understanding

Seed conoscenza sintetica:

```
php artisan product-vault:seed-understanding-knowledge
```

Esecuzione fixture:

```
php artisan product-vault:run-understanding-fixtures
```

Test completo Product Understanding:

```
php artisan product-vault:test-understanding
```

Le fixture coprono scenari come:

* feedback matcher;
* Python similarity;
* pipeline sintetica raw text -> righe -> candidati;
* EAN inline;
* EAN in colonna;
* seriale in colonna;
* quantità/prezzo/totale coerenti;
* prodotti simili ma diversi;
* varianti OCR/testuali;
* warning e guardrail.

## Comando warranty lifecycle

Test ciclo garanzia:

```
php artisan product-vault:test-warranty-lifecycle
```

Il test copre:

* creazione garanzia automatica;
* idempotenza;
* regole categoria;
* prodotto senza `purchase_date`;
* eventi lifecycle automatici e manuali.

## Documenti smoke caricati

Sono stati generati e caricati documenti smoke con ID locali:

* 17: `PV_smoke_01_fattura_ean_nuovi_prodotti.pdf`
* 18: `PV_smoke_02_fattura_seriali_nuovi_prodotti.pdf`
* 19: `PV_smoke_03_conferma_ordine_varianti.pdf`
* 20: `PV_smoke_04_documento_non_pertinente.pdf`

Risultati principali:

* documento 17: buono, 3 righe, 3 candidati, EAN presenti, status `needs_review`;
* documento 18: buono, 3 righe, 3 candidati, seriali presenti;
* documento 19: parziale, order confirmation con problemi su numeri modello interpretati come quantità/prezzo;
* documento 20: accettabile per MVP1, 0 righe e 0 candidati, classificazione migliorabile.

Questi ID sono locali e non vanno considerati riferimenti universali del progetto.

## Problemi noti rimandati

### Python similarity troppo permissiva

L'analyzer Python può proporre match deboli intorno al 35-40% verso prodotti non realmente collegati.

In UI questi match vengono nascosti o declassati, ma il problema a monte resta da correggere.

Possibili interventi futuri:

* soglia minima più rigida;
* token overlap obbligatorio;
* guardrail lessicale;
* soppressione del `best_match` sotto soglia;
* distinzione più netta tra match utile e dato diagnostico.

### Parser order confirmation

Nei documenti e-commerce o conferme ordine, alcuni numeri presenti nel nome o modello prodotto possono essere interpretati male come quantità, prezzo o totale.

Questo va corretto prima di considerare affidabile il supporto agli `order_confirmation`.

### Merchant parser

In alcuni PDF smoke il venditore viene letto come "Righe documento".

Serve migliorare il parser per evitare intestazioni tecniche o sezioni tabellari trattate come merchant.

### Classification documenti non pertinenti

Un documento non pertinente può essere classificato in modo imperfetto, ad esempio come receipt, ma senza generare righe o candidati.

Per MVP1 è accettabile, ma va migliorato in futuro per evitare rumore UX.

### Duplicazione logica candidato

Parte della logica di presentazione candidato è duplicata tra dettaglio documento e pagina Revisioni.

Per ora non va rifattorizzata: è meglio consolidare MVP. In futuro si potrà estrarre una logica comune o componenti condivisi.

### Knowledge base iniziale

La knowledge base iniziale è un punto strategico.

Il prodotto non deve dipendere solo dal caricamento manuale infinito di documenti da parte dell'utente. Serve un sistema per iniettare conoscenza base in un database pulito.

Questo sarà uno dei prossimi blocchi di progettazione.

## Stato attuale

Alla chiusura del blocco Revisioni:

* `php artisan product-vault:test-understanding` è verde;
* `php artisan product-vault:test-warranty-lifecycle` è verde;
* il branch `pv-understanding-scenarios` è stato unito in `main`;
* il push di `main` è andato a buon fine.

La sezione Revisioni è chiusa come prima versione funzionale MVP, non come versione definitiva.