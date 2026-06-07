# Product Understanding — Testing e Smoke Test

Questo documento descrive come testare il livello **Product Understanding** di Product Vault durante lo sviluppo.

Il Product Understanding non deve sostituire la business logic Laravel. Serve a produrre segnali osservabili e verificabili per aiutare:

- revisione manuale;
- generazione candidati prodotto;
- conoscenza progressiva del sistema;
- fuzzy matching tecnico;
- controllo di regressioni su parsing prodotto.

## Comandi principali

### Test completo Product Understanding

```bash
php artisan product-vault:test-understanding
```

Esegue in sequenza:

```bash
php artisan product-vault:seed-understanding-knowledge
php artisan product-vault:run-understanding-fixtures
```

È il comando da usare normalmente durante lo sviluppo.

### Test completo partendo da database pulito

```bash
php artisan product-vault:test-understanding --fresh
```

Esegue:

```bash
php artisan migrate:fresh --seed
php artisan product-vault:seed-understanding-knowledge
php artisan product-vault:run-understanding-fixtures
```

Usarlo solo in ambiente locale/testing, perché cancella il database locale.

### Seed knowledge controllata

```bash
php artisan product-vault:seed-understanding-knowledge
```

Crea una base controllata di conoscenza sintetica:

- utente test;
- team/workspace test;
- global facts EAN-based;
- feedback workspace confermati/ignorati.

Questa knowledge è sintetica, privacy-safe e serve solo per sviluppo/test.

### Fixture Product Understanding

```bash
php artisan product-vault:run-understanding-fixtures
```

Esegue scenari versionati da:

```text
tests/Fixtures/ProductUnderstanding/scenarios.php
```

Le fixture testano:

- feedback matcher locale;
- Python fuzzy similarity;
- pipeline sintetica `raw_text → righe → candidati`;
- EAN inline;
- EAN in colonna;
- seriale in colonna;
- prodotti simili ma diversi;
- varianti OCR/testuali;
- coerenza `quantità × prezzo unitario = totale riga`.

### Regression documenti reali locali

```bash
php artisan product-vault:regression-documents
```

Usa documenti già presenti nel database locale.

Dopo un reset del database, questa regression può non essere utilizzabile finché non vengono ricaricati documenti reali e aggiornate le aspettative.

## Differenza tra fixture e regression documenti

Le fixture Product Understanding sono sintetiche, veloci e ripetibili.

Servono a verificare regole controllate come:

```text
candidate name
→ feedback matcher
→ global fact matcher
→ Python similarity
→ metadata candidato
```

oppure:

```text
raw_text sintetico
→ DocumentLineParser
→ ProductCandidateGenerator
→ metadata Product Understanding
```

La regression documenti reali serve invece a verificare il comportamento end-to-end su file caricati:

```text
upload
→ text extraction
→ classification
→ parsing
→ line parsing
→ candidate generation
```

Le fixture non sostituiscono la regression documenti: la affiancano.

## Regole di sviluppo

Non committare:

```text
.env
.venv
storage
file caricati manualmente
output OCR temporanei
PDF/PNG generati per test manuali non versionati
```

Si possono committare:

```text
tests/Fixtures/ProductUnderstanding/*
docs/development/*
script Python sorgente
requirements.txt
comandi Artisan
```

## Quando aggiungere una fixture

Aggiungere una fixture quando:

- una regola deve restare stabile nel tempo;
- un bug è stato corretto e non deve riapparire;
- un caso reale può essere rappresentato con raw text sintetico;
- il test non richiede OCR/layout reale.

Non aggiungere fixture per ogni variazione casuale di documento.

Preferire fixture che coprono una classe di problema.

Esempi buoni:

```text
Gen 10 ≠ Gen 11
HDMI 2 porte ≠ Dual HDMI 4K
EAN inline estratto e rimosso dalla descrizione
EAN in colonna separata
Seriale in colonna separata
Quantità × prezzo unitario ≠ totale riga
Quantità 2 × prezzo unitario = totale riga
```

## Smoke test manuale documenti

Dopo il blocco Product Understanding sono stati caricati 4 documenti sintetici reali per verificare il comportamento della pipeline su upload effettivo.

Documenti caricati:

| ID | File | Scopo |
|---:|---|---|
| 17 | `PV_smoke_01_fattura_ean_nuovi_prodotti.pdf` | Fattura con EAN e nuovi prodotti |
| 18 | `PV_smoke_02_fattura_seriali_nuovi_prodotti.pdf` | Fattura con seriali e nuovi prodotti |
| 19 | `PV_smoke_03_conferma_ordine_varianti.pdf` | Conferma ordine e-commerce con varianti |
| 20 | `PV_smoke_04_documento_non_pertinente.pdf` | Documento non pertinente |

## Esito documento 17

**File:** `PV_smoke_01_fattura_ean_nuovi_prodotti.pdf`

**Esito:** buono.

Ha prodotto:

```text
3 righe
3 candidati
status = needs_review
EAN presenti sui candidati
prezzi corretti
Product Understanding coerente
```

Prodotti rilevati:

- Monitor ViewMax Creator XR27 4K;
- NAS TerraVault Home Duo 8TB;
- Robot Aspirapolvere CasaBot MappaPro 900.

Nota da correggere più avanti:

```text
merchant letto come "Righe documento"
```

Priorità: media.

## Esito documento 18

**File:** `PV_smoke_02_fattura_seriali_nuovi_prodotti.pdf`

**Esito:** buono.

Ha prodotto:

```text
3 righe
3 candidati
seriali presenti sui candidati
prezzi corretti
status = needs_review
```

Prodotti rilevati:

- Fotocamera LumioShot Z5 Mirrorless;
- Obiettivo LumioPrime 35mm F1.8;
- Stabilizzatore Gimbal SteadyCam Mini 3.

Nota da correggere più avanti:

```text
Python similarity mostra best_match a bassa similarità verso prodotti globali non pertinenti.
Non è pericoloso perché il segnale è low_similarity_to_global_canonical_name, ma può creare rumore in UI.
```

Priorità: bassa/UI.

## Esito documento 19

**File:** `PV_smoke_03_conferma_ordine_varianti.pdf`

**Esito:** parziale. Da non usare ancora in regression.

Problema:

```text
order_confirmation con righe amount_based
numeri nel nome/modello interpretati come quantità o parte del prezzo
```

Esempi osservati:

```text
Monitor View Max Creator XR UHD
quantity = 27
unit_price = 1389.90
total_price = 389.90

TerraVault Home Duo NAS TB
quantity = 8
unit_price = 1529.00
total_price = 529.00

CasaBot Mappa Pro robot aspirapolvere
quantity = 900
unit_price = 1349.50
total_price = 349.50
```

Decisione:

```text
rimandare la correzione a dopo il primo blocco Warranty lifecycle
```

Priorità: alta post-garanzia.

## Esito documento 20

**File:** `PV_smoke_04_documento_non_pertinente.pdf`

**Esito:** buono per MVP1.

Ha prodotto:

```text
0 righe
0 candidati
nessun prodotto generato
```

Nota:

```text
classificato come receipt, ma senza candidati.
Per MVP1 è accettabile.
Più avanti valutare tipo unknown/non_relevant.
```

Priorità: bassa.

## Correzioni rimandate

| Priorità | Area | Problema | Decisione |
|---|---|---|---|
| Alta | Order confirmation parser | Numeri nel nome prodotto interpretati come quantità/prezzo | Rimandare a dopo primo blocco garanzie |
| Media | Merchant parser | Merchant letto come `Righe documento` nei PDF smoke 17/18 | Rimandare |
| Bassa | Product Understanding UI | `best_match` Python a bassa similarità può creare rumore | Rimandare/UI later |
| Bassa | Classification | Documento non pertinente classificato come receipt ma senza candidati | Rimandare |

## Stato attuale

Il blocco Product Understanding è considerato abbastanza stabile per procedere al Warranty lifecycle.

Non è finito definitivamente, ma non deve bloccare l’avanzamento dell’MVP.

Prossimo blocco funzionale:

```text
Product
→ Warranty
→ regole garanzia iniziali
→ stato garanzia
→ pagina dettaglio prodotto con sezione garanzia
```