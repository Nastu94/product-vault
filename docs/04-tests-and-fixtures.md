# Product Vault - Test e fixture

Questo documento descrive i comandi di test, le fixture e gli scenari smoke usati per validare Product Vault durante lo sviluppo MVP.

L'obiettivo dei test attuali non è dimostrare che il sistema riconosca ogni documento possibile, ma impedire regressioni sui flussi più importanti già costruiti:

* Product Understanding;
* generazione candidati;
* feedback e global facts;
* analyzer Python;
* garanzie;
* lifecycle prodotto;
* revisioni.

## Principio guida

Un test verde non significa che Product Vault sia completo.

Un test verde significa che gli scenari coperti continuano a comportarsi come previsto.

Questa distinzione è importante perché Product Vault lavora su documenti reali, OCR imperfetti, PDF diversi, conferme ordine, fatture, scontrini, seriali, EAN e casi non pertinenti. La copertura crescerà gradualmente con nuovi casi reali.

## Comandi principali

I comandi principali da eseguire dopo ogni micro-patch sono:

```
php artisan optimize:clear

php artisan product-vault:test-understanding

php artisan product-vault:test-warranty-lifecycle
```

Questi comandi rappresentano la baseline attuale.

Anche per patch documentali è consigliato rieseguirli, per mantenere disciplina di sviluppo e confermare che il repository rimanga in stato verde.

## Pulizia cache

Comando:

```
php artisan optimize:clear
```

Serve a pulire cache di configurazione, route, view e container.

Va eseguito prima dei test principali, soprattutto dopo modifiche a:

* config;
* service provider;
* command;
* route;
* view;
* classi caricate dal container;
* permessi o contesto applicativo.

## Test Product Understanding

Comando:

```
php artisan product-vault:test-understanding
```

Questo è il comando principale per validare il blocco Product Understanding.

Copre scenari legati a:

* feedback matcher;
* global facts;
* analyzer Python;
* similarità testuale;
* guardrail;
* EAN;
* seriali;
* parsing sintetico;
* righe prodotto;
* candidati;
* warning;
* casi simili ma diversi.

## Test warranty lifecycle

Comando:

```
php artisan product-vault:test-warranty-lifecycle
```

Questo comando valida il ciclo garanzie e lifecycle prodotto.

Copre:

* creazione automatica garanzia;
* uso di `WarrantyRule`;
* idempotenza;
* regole categoria;
* prodotto senza `purchase_date`;
* eventi lifecycle automatici;
* eventi lifecycle manuali;
* modifica manuale garanzia;
* creazione manuale garanzia.

Il comando deve restare verde dopo modifiche a:

* conferma candidato;
* creazione prodotto;
* collegamento documento-prodotto;
* garanzie;
* regole garanzia;
* eventi lifecycle;
* ProductFromCandidateCreator;
* DefaultWarrantyCreator;
* ProductLifecycleEventRecorder.

## Seed Product Understanding

Comando:

```
php artisan product-vault:seed-understanding-knowledge
```

Questo comando crea una base sintetica controllata per testare il Product Understanding.

Crea o prepara:

* utente/team test;
* global facts EAN-based;
* feedback workspace;
* candidati o contesti utili agli scenari;
* dati coerenti per fixture ripetibili.

Il seed non rappresenta una knowledge base reale completa. Serve a costruire un ambiente prevedibile per test e sviluppo.

## Fixture Product Understanding

Comando:

```
php artisan product-vault:run-understanding-fixtures
```

Questo comando esegue scenari controllati del Product Understanding.

Le fixture sono pensate per testare casi specifici, non per simulare tutto il mondo reale.

## Test completo Product Understanding

Comando:

```
php artisan product-vault:test-understanding
```

Questo comando orchestri il controllo complessivo Product Understanding.

È il comando da usare come riferimento principale, perché integra più verifiche in una singola esecuzione.

## Scenari coperti dalle fixture

### Feedback matcher

Verifica che il sistema sappia usare conferme e ignore precedenti.

Obiettivo:

* riconoscere candidati simili a prodotti già confermati;
* riconoscere candidati simili a righe già ignorate;
* produrre segnali come `suggested_bias` e `review_hint`;
* evitare che ogni documento venga trattato come se il sistema non avesse memoria.

### Python similarity

Verifica l'integrazione tra PHP e analyzer Python.

Obiettivo:

* invocare correttamente lo script Python;
* leggere output JSON;
* salvare risultato nei metadata;
* distinguere match forti, match deboli e warning;
* rilevare casi di modello simile ma diverso;
* rilevare varianti OCR.

### Pipeline sintetica raw text -> righe -> candidati

Verifica che un testo grezzo controllato possa attraversare la pipeline fino alla generazione candidati.

Obiettivo:

* evitare regressioni sul parsing base;
* assicurare che righe prodotto generino candidati;
* evitare candidati da righe non pertinenti;
* mantenere coerenza tra testo, righe e candidati.

### EAN inline

Verifica il caso in cui l'EAN è presente nella stessa riga del prodotto.

Esempio concettuale:

* `Notebook Lenovo ThinkPad X1 Carbon Gen 11 EAN 0196388123456`

Obiettivo:

* estrarre EAN;
* associarlo al candidato corretto;
* usarlo come segnale forte;
* attivare eventuali global facts.

### EAN in colonna

Verifica il caso in cui l'EAN è presente in una colonna separata o in una struttura tabellare.

Obiettivo:

* non perdere il codice solo perché non è inline;
* associare il codice alla riga prodotto corretta;
* evitare di trasformare il codice in prezzo, quantità o descrizione.

### Seriale in colonna

Verifica il caso in cui un seriale è presente in una colonna dedicata.

Obiettivo:

* riconoscere il seriale;
* non confonderlo con EAN;
* salvarlo come identificatore dell'unità specifica;
* usarlo nel candidato/prodotto quando confermato.

### Quantità, prezzo e totale coerenti

Verifica che quantità, prezzo unitario e totale vengano interpretati in modo coerente.

Obiettivo:

* distinguere quantità da prezzo;
* distinguere prezzo unitario da totale riga;
* non usare numeri di modello come prezzo;
* rilevare righe prodotto plausibili.

### Prodotti simili ma diversi

Verifica casi in cui due prodotti condividono molti token, ma non sono lo stesso prodotto.

Esempio concettuale:

* `ThinkPad X1 Carbon Gen 10`
* `ThinkPad X1 Carbon Gen 11`

Obiettivo:

* rilevare similarità alta;
* non ignorare differenza di modello;
* produrre guardrail;
* evitare canonicalizzazione automatica aggressiva.

### Varianti OCR/testuali

Verifica casi in cui il testo contiene errori OCR o varianti minori.

Esempio concettuale:

* `Duat HOMI`
* `Dual HDMI`

Obiettivo:

* riconoscere che può trattarsi di variante OCR;
* usare EAN o altri segnali forti per compensare;
* non trattare ogni differenza testuale come prodotto diverso.

### Warning e guardrail

Verifica che il sistema produca warning utili quando la conoscenza è debole o rischiosa.

Esempi:

* model conflict;
* spec difference;
* weak global facts;
* no global facts;
* match Python debole;
* similarity non sufficiente.

## Analyzer Python

File principale:

```
tools/product_understanding/analyze_product_text.py
```

L'analyzer usa RapidFuzz.

Dipendenze Python rilevanti:

```
tools/product_understanding/requirements.txt
```

Dipendenza principale:

```
rapidfuzz>=3,<4
```

La versione installata localmente durante sviluppo è RapidFuzz 3.14.5.

## Cosa validare quando si tocca Python similarity

Dopo modifiche allo script Python o al service PHP che lo invoca, verificare:

* output JSON valido;
* gestione errori;
* match forte;
* match debole;
* modello simile ma diverso;
* variante OCR;
* assenza di global facts;
* global facts forti;
* soglie di confidence;
* salvataggio metadata candidato.

Comando minimo:

```
php artisan product-vault:test-understanding
```

Se la modifica è importante, aggiungere anche verifiche manuali con Tinker o CLI Python.

## Smoke test documenti

Sono stati generati e caricati documenti smoke locali per validare casi più realistici.

Documenti:

* 17: `PV_smoke_01_fattura_ean_nuovi_prodotti.pdf`
* 18: `PV_smoke_02_fattura_seriali_nuovi_prodotti.pdf`
* 19: `PV_smoke_03_conferma_ordine_varianti.pdf`
* 20: `PV_smoke_04_documento_non_pertinente.pdf`

Questi ID sono locali e possono cambiare in altri database. Non devono essere usati come riferimenti assoluti nel codice.

## Risultati smoke

### Documento 17

Esito:

* buono;
* 3 righe;
* 3 candidati;
* EAN presenti;
* prezzi corretti;
* status `needs_review`.

Interpretazione:

* scenario positivo per fattura con EAN;
* utile per verificare parsing righe e associazione EAN-candidato.

### Documento 18

Esito:

* buono;
* 3 righe;
* 3 candidati;
* seriali presenti;
* prezzi corretti.

Interpretazione:

* scenario positivo per prodotti con seriali;
* utile per distinguere seriale da EAN e dati prezzo.

### Documento 19

Esito:

* parziale;
* conferma ordine/e-commerce;
* parsing `amount_based`;
* numeri nel nome/modello interpretati male come quantità o prezzi.

Interpretazione:

* scenario utile ma non ancora risolto;
* evidenzia il problema del parser `order_confirmation`;
* da correggere prima di considerare affidabile il supporto e-commerce.

### Documento 20

Esito:

* accettabile per MVP1;
* documento non pertinente;
* 0 righe;
* 0 candidati;
* classificazione ancora migliorabile.

Interpretazione:

* il comportamento più importante è non generare candidati falsi;
* la classificazione può essere migliorata più avanti.

## Come interpretare gli smoke test

Gli smoke test non sostituiscono fixture e comandi artisan.

Le fixture sono ripetibili e controllate.

Gli smoke test sono più vicini a casi reali, ma possono dipendere da:

* database locale;
* documenti caricati;
* ID locali;
* file storage;
* ambiente OCR;
* stato della knowledge base;
* dati già confermati o ignorati.

Per questo motivo gli smoke test vanno documentati, ma non trattati come test automatici rigidi finché non vengono trasformati in fixture ripetibili.

## Casi noti non ancora risolti

### Order confirmation parser

Problema:

* numeri dentro nomi prodotto o modelli vengono interpretati come quantità, prezzo o totale.

Esempi concettuali:

* generazione prodotto;
* capacità;
* numero porte;
* modello;
* versione.

Rischio:

* righe prodotto distorte;
* prezzi errati;
* quantità errate;
* candidati meno affidabili.

Priorità:

* importante, ma rimandato dopo documentazione e backlog.

### Merchant parser

Problema:

* in alcuni PDF smoke il venditore viene letto come `Righe documento`.

Rischio:

* merchant errato;
* dati documento poco credibili;
* UI confusa;
* possibili effetti su Product Understanding.

Priorità:

* importante, ma non bloccante per la documentazione.

### Classification documento non pertinente

Problema:

* documento non pertinente può essere classificato come receipt o tipo simile.

Comportamento attuale accettabile:

* non genera righe;
* non genera candidati.

Priorità:

* migliorare in futuro per ridurre rumore UX.

### Python similarity debole

Problema:

* match deboli possono apparire verso prodotti non realmente collegati.

Mitigazione attuale:

* UI declassa o nasconde match sotto soglia come suggerimenti utili.

Correzione futura:

* risolvere a monte nell'analyzer o nel service PHP.

## Quando aggiungere una nuova fixture

Aggiungere una fixture quando:

* un bug viene corretto e non deve tornare;
* emerge un nuovo pattern documento ricorrente;
* un parser viene modificato;
* un guardrail viene introdotto;
* un comportamento Product Understanding diventa decisione stabile;
* un documento smoke rivela un caso generalizzabile.

Non aggiungere fixture per ogni singolo documento casuale se il caso non è ancora capito. Prima va isolato il pattern.

## Come trasformare uno smoke test in fixture

Procedura consigliata:

1. identificare il comportamento osservato;
2. isolare il testo minimo che riproduce il problema;
3. rimuovere dettagli non necessari;
4. creare input sintetico stabile;
5. definire output atteso;
6. aggiungere fixture;
7. eseguire `product-vault:test-understanding`;
8. documentare il caso se rilevante.

Questo evita fixture fragili basate su documenti interi difficili da mantenere.

## Regola: fixture sintetiche prima di fixture enormi

Quando possibile, preferire fixture sintetiche.

Una fixture sintetica è migliore quando:

* riproduce il bug con poche righe;
* è leggibile;
* non dipende da OCR;
* non dipende da file storage;
* non dipende da PDF;
* non contiene dati personali;
* è facile da aggiornare.

I documenti reali o generati restano utili per smoke test e diagnosi, ma non devono diventare subito la base unica dei test automatici.

## Validazione manuale con Tinker

Tinker resta utile per ispezionare dati locali.

Comando:

```
php artisan tinker
```

Usarlo quando serve controllare:

* un documento specifico;
* righe estratte;
* candidati;
* metadata candidato;
* global facts;
* feedback;
* garanzie;
* eventi lifecycle.

Tinker non deve sostituire i test automatici, ma è utile durante diagnosi e micro-patch.

## Verifiche manuali consigliate dopo modifiche importanti

### Dopo modifiche al parsing documenti

Verificare:

* righe generate;
* quantità;
* prezzo unitario;
* totale riga;
* line type;
* candidati generati;
* document status;
* warnings.

### Dopo modifiche a Product Understanding

Verificare:

* metadata candidato;
* feedback matcher;
* global fact matcher;
* Python analysis;
* guardrail;
* badge UI;
* drawer Reviews.

### Dopo modifiche a garanzie

Verificare:

* prodotto creato;
* garanzia generata;
* idempotenza;
* eventi lifecycle;
* pagina prodotto;
* pagina `/warranties`;
* dashboard.

### Dopo modifiche a Reviews

Verificare:

* filtri;
* lista candidati;
* badge;
* drawer;
* conferma;
* ignore;
* link documento;
* link prodotto;
* comportamento candidati confermati.

## Cosa non testiamo ancora bene

La copertura attuale non è completa.

Aree ancora deboli:

* UI Livewire completa;
* regressioni visuali;
* parsing di molti layout reali;
* classificazione documenti non pertinenti;
* conferme ordine e-commerce;
* merchant parser;
* gestione documenti multi-pagina complessi;
* OCR difficile;
* performance;
* permessi avanzati multi-team;
* notifiche future;
* audit log completo;
* eliminazione/retention file.

Queste lacune sono accettabili per MVP, purché siano note e documentate.

## Disciplina di sviluppo

Dopo ogni micro-patch:

1. controllare stato Git;
2. eseguire cache clear;
3. eseguire test principali;
4. committare solo file intenzionali;
5. non committare file temporanei;
6. pushare solo quando il repository è verde.

Comandi tipici:

```
git status

php artisan optimize:clear

php artisan product-vault:test-understanding

php artisan product-vault:test-warranty-lifecycle

git add <file>

git commit -m "<messaggio>"

git push origin main
```

## File da non committare

Non committare:

* `.env`;
* file caricati manualmente;
* storage locale;
* output OCR temporanei;
* virtual environment Python;
* cache;
* log locali;
* dump temporanei;
* file generati solo per debug;
* documenti personali o sensibili.

## Relazione con la documentazione

Questo documento deve essere aggiornato quando:

* viene aggiunto un nuovo comando test;
* viene aggiunta una fixture importante;
* viene corretto un caso smoke;
* cambia la strategia di testing;
* un bug noto diventa test automatico;
* un test esistente cambia significato.

La documentazione dei test deve restare concreta. Non deve descrivere test desiderati come se fossero già presenti.

## Backlog test

### P0 - Consolidamento

* Mantenere verdi `product-vault:test-understanding` e `product-vault:test-warranty-lifecycle`.
* Trasformare bug corretti in fixture sintetiche.
* Documentare ogni nuovo scenario stabile.
* Evitare fixture troppo legate a ID locali.

### P1 - Miglioramenti utili

* Aggiungere fixture per `order_confirmation`.
* Aggiungere fixture per merchant parser.
* Aggiungere fixture per documento non pertinente.
* Aggiungere fixture per similarity debole soppressa a monte.
* Aggiungere fixture per token overlap.
* Aggiungere fixture per match con brand incompatibile.

### P2 - Evoluzione futura

* Test UI Livewire più strutturati.
* Test autorizzazioni multi-team.
* Test upload file con storage fake.
* Test OCR con fixture controllate.
* Test performance pipeline.
* Test notifiche garanzia.
* Test retention/cancellazione.
* Dataset smoke versionato e non sensibile.

## Decisione strategica

La strategia corretta per Product Vault è combinare tre livelli:

1. fixture sintetiche per casi logici;
2. smoke test documentali per casi realistici;
3. verifiche manuali mirate per UI e diagnosi.

Le fixture impediscono regressioni.

Gli smoke test mostrano dove il sistema fallisce nel mondo reale.

Le verifiche manuali aiutano a capire cosa correggere prima di automatizzare.

Il prossimo passo, dopo la documentazione e il backlog, sarà progettare una knowledge base iniziale che possa essere caricata in modo controllato su database pulito.