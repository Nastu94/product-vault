# Product Vault — Roadmap operativa post-BATCH02

**Versione:** 1.1
**Data:** 2026-06-26
**Stato di partenza:** BATCH02 completato e integrato in `main`  
**Ambito:** evoluzione da motore documentale robusto a prodotto MVP utilizzabile e monetizzabile

---

## 1. Scopo del documento

Questo documento definisce come proseguire dopo la chiusura di BATCH02.

BATCH02 ha consolidato il riconoscimento strutturale di documenti differenti e ha confermato che Product Vault può:

- classificare documenti pertinenti e non pertinenti;
- estrarre righe prodotto da fatture, conferme ordine e ricevute;
- gestire descrizioni multilinea e importi separati;
- distinguere numeri tecnici da quantità e prezzi;
- recuperare strutture OCR frammentate;
- evitare candidati da documenti irrilevanti;
- separare `Recognition Quality` da `Product Completion`;
- validare in modo controllato i risultati tramite batch e regression.

La conclusione corretta non è che il parser sia completo o universale.

La conclusione corretta è:

> Il riconoscimento strutturale ha raggiunto una baseline sufficientemente solida per smettere di espandere orizzontalmente i casi documentali e iniziare a trasformare i candidati riconosciuti in un’esperienza utente utile.

Da questo momento il rischio principale non è soltanto perdere una riga prodotto. Il rischio è continuare a migliorare il parser senza costruire il flusso che rende Product Vault realmente utilizzabile.

---

## 2. Stato raggiunto con BATCH02

BATCH02 ha coperto dieci documenti e ha portato tutti gli scenari attesi a `Recognition OK`.

Le principali aree consolidate sono:

### 2.1 Fatture

- descrizioni multilinea;
- colonne ambigue;
- tabelle multipagina;
- formati decimali internazionali;
- prezzi unitari netti;
- codici prodotto presenti su righe visuali successive;
- recupero tramite layout OCR quando il testo lineare non è sufficiente.

### 2.2 Conferme ordine ed e-commerce

- importi separati dalla descrizione;
- quantità implicite o esplicite;
- numeri tecnici da non interpretare come quantità;
- righe prodotto distribuite su più linee;
- parsing conservativo in presenza di strutture non tabellari.

### 2.3 Ricevute OCR

- descrizione separata dalla riga quantità × prezzo;
- totale prodotto su linea distinta;
- ricostruzione della relazione tra evidenze visuali;
- generazione di candidati con evidenze strutturate.

### 2.4 Documenti irrilevanti

- classificazione esplicita come `irrelevant`;
- nessuna generazione di righe prodotto operative;
- nessun candidato prodotto;
- controlli nel processing job e nella validazione batch;
- fallimento esplicito del contratto di riconoscimento se un documento irrilevante produce dati prodotto.

### 2.5 Contratto di qualità

Il report distingue ora:

- **Recognition Quality**: tipo documento, righe, candidati, importi, recovery e problemi strutturali;
- **Product Completion**: brand, categoria, modello, knowledge e altri campi migliorabili.

Questa separazione deve restare stabile.

Un brand mancante non è un errore del parser.  
Una riga di pagamento trasformata in prodotto è invece una regressione strutturale.

---

## 3. Decisione principale post-BATCH02

Non deve essere avviato immediatamente un BATCH03 costruito soltanto per trovare altri formati documentali e aggiungere nuove eccezioni.

Il ciclo da evitare è:

```text
nuovo batch
→ nuovo formato marginale
→ nuova regola specifica
→ batch verde
→ altro formato marginale
→ altra regola specifica
```

Questo percorso migliorerebbe il laboratorio tecnico, ma ritarderebbe il prodotto.

La priorità successiva è:

```text
recognition robusto
→ assisted review
→ conferma prodotto affidabile
→ coperture trasparenti
→ lifecycle prodotto
→ prima azione utile per l’utente
```

BATCH02 deve diventare una baseline di regressione, non l’inizio di una sequenza infinita di batch dello stesso tipo.

---

## 4. Ordine delle prossime fasi

La roadmap consigliata è:

1. congelamento e documentazione della baseline BATCH02;
2. assisted review backend;
3. review UI guidata;
4. hardening della creazione prodotto;
5. traduzione dei segnali tecnici in indicazioni comprensibili per l’utente;
6. evoluzione della garanzia in copertura contestualizzata;
7. primo workflow operativo “Ho un problema”;
8. dashboard orientata alle azioni;
9. monetizzazione soltanto dopo il completamento di un flusso utile;
10. eventuale BATCH03 mirato a nuovi rischi reali, non alla conoscenza infinita.

La fase sul linguaggio dei segnali appartiene logicamente al blocco Assisted Review, ma viene pianificata operativamente dopo il confirmation hardening, così da non interrompere il consolidamento transazionale già avviato.

Le fasi devono essere realizzate tramite branch e micro-patch separate.

---

# PARTE I — CHIUSURA DEL BLOCCO RECOGNITION

## 5. Fase 0 — Congelare la baseline BATCH02

### Obiettivo

Rendere BATCH02 un contratto stabile contro cui verificare tutte le patch future.

### Azioni

- conservare i dieci documenti di BATCH02 come set controllato;
- conservare il file `BATCH02_expected.json`;
- non modificare gli expected per far passare regressioni introdotte da patch successive;
- modificare gli expected soltanto quando viene dimostrato che l’aspettativa precedente era errata;
- documentare i casi coperti da ciascun file;
- mantenere BATCH01 e BATCH02 separati;
- considerare entrambi parte della regression minima documentale.

### Baseline di test

Dopo ogni patch che tocca processing, classificazione, righe, candidati o conferma prodotto devono restare verdi almeno:

```bash
php artisan optimize:clear

php artisan product-vault:regression-documents

php artisan product-vault:test-understanding

php artisan product-vault:test-recognition-quality-contract

php artisan product-vault:test-warranty-lifecycle

php artisan product-vault:validate-document-batch \
    --expected=storage/app/testing/BATCH01_expected.json \
    --filename=BATCH01

php artisan product-vault:validate-document-batch \
    --expected=storage/app/testing/BATCH02_expected.json \
    --filename=BATCH02
```

Durante il debug può essere aggiunto `--show-candidates`.

### Criterio di uscita

La fase è conclusa quando:

- `main` contiene le patch BATCH02;
- tutti i comandi risultano verdi;
- BATCH01 e BATCH02 sono considerati immutabili salvo correzioni motivate;
- nessun warning di completezza viene usato come errore strutturale.

---

# PARTE II — ASSISTED REVIEW

## 6. Fase 1 — Assisted review backend

### Obiettivo

Preparare dati coerenti che permettano all’utente di confermare rapidamente un candidato senza trasformare suggerimenti in verità automatiche.

Questa è la fase immediatamente successiva consigliata.

### Branch consigliato

```text
pv-assisted-review-foundation
```

### Principio

Ogni campo importante deve poter distinguere tra:

- valore estratto dal documento;
- valore derivato con regola deterministica;
- valore suggerito;
- valore confermato dall’utente;
- valore modificato manualmente;
- valore sconosciuto.

Non basta salvare `brand_id` o `category_id`. È necessario sapere perché quel valore è presente e quale livello di affidabilità possiede.

### Contratto metadata consigliato

Per la prima implementazione si può evitare una nuova struttura relazionale complessa e usare metadata versionati sul candidato.

Esempio concettuale:

```php
'assisted_review' => [
    'version' => 'v1',
    'needs_user_completion' => true,
    'fields' => [
        'brand' => [
            'current_value' => null,
            'suggested_value' => 'Kingston',
            'source' => 'name_structure',
            'confidence' => 78,
            'status' => 'suggested',
        ],
        'category' => [
            'current_value' => 'computers',
            'suggested_value' => 'computers',
            'source' => 'product_type_mapping',
            'confidence' => 90,
            'status' => 'suggested',
        ],
        'model' => [
            'current_value' => null,
            'suggested_value' => 'NV3',
            'source' => 'name_structure',
            'confidence' => 72,
            'status' => 'suggested',
        ],
    ],
]
```

Il formato definitivo può cambiare, ma deve rispettare questi concetti:

- `version`;
- campo interessato;
- valore corrente;
- valore suggerito;
- origine;
- confidenza;
- stato;
- necessità o meno di intervento utente.

### Suggerimenti ammessi nel primo blocco

#### Brand

Il brand può essere suggerito usando:

- brand già riconosciuto con match forte;
- struttura del nome prodotto;
- feedback confermati nello stesso team;
- global fact forte;
- initial knowledge controllata.

Non deve essere creato automaticamente un nuovo brand globale da una singola riga.

#### Categoria

La categoria deve restare macro e derivare prevalentemente dal tipo prodotto.

Esempi:

- notebook → `computers`;
- monitor → `computers`;
- cuffie → `tv-audio` o `electronics`;
- deumidificatore → `climate-control` o `small-appliances`;
- cavo → `accessories`.

Non serve una tassonomia infinita.

#### Modello

Il modello può essere suggerito quando una sequenza è plausibile e distinta da:

- quantità;
- prezzo;
- EAN;
- seriale;
- capacità o specifiche tecniche generiche.

Il modello non deve diventare obbligatorio per confermare il prodotto.

### Cosa non fare

- non introdurre AI obbligatoria;
- non salvare automaticamente ogni suggerimento;
- non aggiungere brand alla knowledge base solo per completare i test;
- non impedire la conferma se brand o modello mancano;
- non modificare il parser strutturale per migliorare esclusivamente il completamento.

### Test richiesti

Creare un comando o estendere test esistenti per coprire:

- candidato completo;
- candidato senza brand;
- candidato senza categoria;
- suggerimento brand forte;
- suggerimento brand debole;
- modello tecnico non confuso con quantità;
- assenza di suggerimenti plausibili;
- metadata versionati e ripetibili;
- rigenerazione idempotente;
- nessuna modifica automatica di valori confermati.

### Criterio di uscita

La fase è conclusa quando ogni candidato può dichiarare chiaramente:

- cosa è stato estratto;
- cosa è stato suggerito;
- cosa manca;
- cosa deve confermare l’utente;
- quali campi non sono indispensabili.

---

## 7. Fase 2 — Review UI guidata

### Obiettivo

Trasformare i metadata di assisted review in una revisione semplice e comprensibile.

### Branch consigliato

```text
pv-assisted-review-ui
```

### Superfici da aggiornare

- pagina review;
- drawer o modal del candidato;
- pagina documento;
- eventuale conferma rapida dalla lista candidati.

### Struttura della schermata

Esempio:

```text
Prodotto trovato
SSD Kingston NV3 1TB

Dati letti dal documento
Prezzo: 64,90 €
Quantità: 1
Data acquisto: 12/06/2026

Da completare
Brand: Kingston — suggerito
Categoria: Computer — suggerita
Modello: NV3 — suggerito

[Conferma prodotto]
[Modifica]
[Ignora]
```

### Linguaggio UI

Usare etichette comprensibili:

- `Estratto dal documento`;
- `Suggerito da Product Vault`;
- `Confermato da te`;
- `Da completare`;
- `Non disponibile`.

Evitare di mostrare direttamente all’utente sigle interne come:

- IK;
- GF;
- FZ;
- PYw;
- raw confidence score senza spiegazione.

Questi dati possono restare disponibili in diagnostica o audit.

### Comportamenti richiesti

- confermare un suggerimento;
- modificare il valore;
- lasciare vuoto un campo opzionale;
- ignorare il candidato;
- visualizzare documento e riga sorgente;
- preservare il nome originale;
- mostrare quantità e importi;
- impedire modifiche invisibili durante la conferma;
- confermare più candidati senza perdere il contesto.

### Criterio di uscita

Un utente non tecnico deve poter trasformare un documento elaborato in prodotti confermati senza comprendere il funzionamento del parser.

Il flusso deve richiedere intervento soltanto sui campi realmente mancanti o incerti.

---

## 7.1 Fase 2.1 — Linguaggio comprensibile dei segnali di revisione

### Collocazione operativa

Questa fase appartiene al blocco Assisted Review, ma deve essere sviluppata dopo la conclusione del confirmation hardening.

Il branch consigliato è:

```text
pv-review-signal-language
```

### Obiettivo

Trasformare i segnali tecnici prodotti dai motori di riconoscimento, similarità e knowledge matching in indicazioni comprensibili e utili per un utente non tecnico.

La diagnostica interna deve restare disponibile per sviluppo e audit, ma la schermata principale di revisione non deve esporre direttamente codici, soglie o messaggi tecnici generati dai singoli analyzer.

### Problema da risolvere

Alcuni candidati mostrano attualmente segnali come:

* `Unusable similarity match`;
* `Similarity below min score`;
* `Insufficient informative token overlap`;
* `Low informative token overlap ratio`;
* `Low similarity to global canonical name`.

Questi messaggi descrivono condizioni tecniche reali, ma non spiegano chiaramente all’utente:

* quale informazione potrebbe essere incerta;
* se il candidato è comunque utilizzabile;
* se è necessario un controllo manuale;
* quale azione è consigliata;
* se il segnale indica un errore oppure soltanto conoscenza insufficiente.

### Principio

La UI deve distinguere tra:

* codice tecnico originale;
* messaggio leggibile;
* gravità;
* campo interessato;
* azione consigliata;
* dettaglio diagnostico opzionale.

Il significato tecnico del segnale non deve essere modificato. Deve cambiare soltanto la sua presentazione.

Il codice originale deve restare nei metadata per audit, regressione e debug.

### Esempi di traduzione

| Segnale tecnico                             | Messaggio mostrato all’utente                                                |
| ------------------------------------------- | ---------------------------------------------------------------------------- |
| `Unusable similarity match`                 | Nessun riferimento sufficientemente affidabile da applicare                  |
| `Similarity below min score`                | Il nome trovato non corrisponde con sufficiente sicurezza ai dati conosciuti |
| `Insufficient informative token overlap`    | Il nome contiene pochi elementi distintivi per un confronto affidabile       |
| `Low informative token overlap ratio`       | Solo una parte limitata del nome coincide con il riferimento disponibile     |
| `Low similarity to global canonical name`   | Il nome letto differisce dal nome prodotto conosciuto                        |
| `Quantity x unit price matches total price` | Quantità e prezzi della riga risultano coerenti                              |

Le traduzioni non devono essere letterali.

Ad esempio:

```text
Similarity below min score
```

non deve diventare:

```text
Similarità sotto il punteggio minimo
```

ma:

```text
Il nome trovato non corrisponde con sufficiente sicurezza ai dati conosciuti.
```

### Presentazione consigliata

La schermata principale deve mostrare al massimo:

* un titolo breve;
* una spiegazione in linguaggio naturale;
* il campo interessato, quando noto;
* un’azione suggerita, quando utile.

Esempio:

```text
Nome prodotto da verificare

Il nome letto differisce dal riferimento disponibile.
Controlla il nome soltanto se riconosci un errore evidente.
```

I dettagli tecnici devono essere collocati in una sezione secondaria, chiusa per impostazione predefinita.

Esempio:

```text
Dettagli tecnici
- source: python_similarity
- signal: low_informative_token_overlap_ratio
- score: 0.31
- threshold: 0.55
```

### Raggruppamento dei segnali

I segnali mostrati nella UI devono essere classificati almeno come:

* informazione positiva;
* verifica consigliata;
* dato mancante;
* possibile incoerenza;
* errore strutturale;
* informazione esclusivamente diagnostica.

Non ogni segnale tecnico deve diventare un warning rosso.

Un confronto di similarità non utilizzabile non significa necessariamente che il candidato sia errato. Può significare semplicemente che Product Vault non dispone di conoscenza sufficiente per completarlo automaticamente.

### Organizzazione consigliata della card

La sezione attualmente chiamata `Segnali` dovrebbe distinguere visivamente:

#### Controlli consigliati

Informazioni che potrebbero richiedere una verifica dell’utente.

#### Dati coerenti

Controlli positivi, come la coerenza tra quantità, prezzo unitario e totale.

#### Dettagli tecnici

Codici originali, similarity score, token overlap, soglie, analyzer e altre informazioni utili soltanto per audit o debug.

### Regole di implementazione

* usare un mapping centralizzato tra codice tecnico e presentazione UI;
* non modificare i messaggi direttamente nei singoli analyzer;
* mantenere il codice tecnico originale nei metadata;
* prevedere un fallback leggibile per segnali sconosciuti;
* evitare testi che trasformino un’incertezza in errore certo;
* mantenere separati warning strutturali e limiti della knowledge;
* non usare il colore rosso per semplici limiti di similarità o conoscenza;
* rendere accessibile la diagnostica completa soltanto nei dettagli;
* preparare il mapping per una futura localizzazione;
* non modificare soglie, score o logica di riconoscimento in questa fase.

### Cosa non fare

* non eliminare i segnali tecnici dai metadata;
* non tradurre letteralmente termini tecnici senza contestualizzarli;
* non mostrare tutti i segnali con la stessa gravità;
* non nascondere regressioni strutturali reali;
* non modificare il parser per migliorare esclusivamente il testo mostrato;
* non trasformare una mancata corrispondenza con la knowledge in un errore del candidato;
* non cambiare il risultato del riconoscimento.

### Test richiesti

Coprire almeno:

* segnale tecnico conosciuto;
* segnale positivo;
* segnale di verifica;
* segnale strutturale;
* segnale sconosciuto con fallback;
* più segnali equivalenti raggruppati;
* conservazione del codice tecnico originale;
* assenza di modifiche ai metadata;
* messaggi principali privi di codici interni;
* diagnostica tecnica ancora accessibile;
* classificazione coerente della gravità;
* nessuna variazione nei risultati del riconoscimento;
* BATCH01 e BATCH02 invariati.

### Criterio di uscita

La fase è conclusa quando un utente non tecnico può comprendere:

* perché il sistema segnala un candidato;
* se il candidato può essere confermato;
* quale dato merita attenzione;
* quale azione è consigliata;
* se il segnale rappresenta un errore reale o soltanto conoscenza insufficiente;

senza conoscere similarity score, token overlap, fuzzy matching, soglie interne o implementazione Python.

---

# PARTE III — CREAZIONE PRODOTTO AFFIDABILE

## 8. Fase 3 — Hardening del passaggio Candidate → Product

### Obiettivo

Assicurare che la conferma utente produca una scheda prodotto coerente, tracciabile e non duplicata.

### Branch consigliato

```text
pv-product-confirmation-hardening
```

### Contratto minimo

Quando un candidato viene confermato, il sistema deve:

1. creare o aggiornare un prodotto;
2. collegarlo al documento sorgente;
3. preservare la riga sorgente e i metadata utili;
4. salvare soltanto valori estratti o confermati;
5. registrare feedback per il team;
6. evitare doppie conferme dello stesso candidato;
7. evitare duplicazioni causate da retry;
8. registrare un evento lifecycle;
9. tentare la creazione della copertura stimata soltanto dopo la conferma.

### Idempotenza

Devono essere coperti almeno questi casi:

- doppio click su conferma;
- retry della richiesta Livewire;
- riesecuzione del job;
- candidato già collegato a prodotto;
- documento rielaborato;
- prodotto già esistente e correttamente riconducibile allo stesso candidato.

Non si deve introdurre una deduplica semantica aggressiva tra prodotti simili.

`ThinkPad X1 Carbon Gen 10` e `Gen 11` non devono essere uniti soltanto perché molto simili.

### Provenienza dei dati

Il prodotto finale deve poter ricostruire:

- documento di origine;
- candidato di origine;
- nome originale;
- valori estratti;
- valori confermati;
- modifiche manuali;
- eventuali suggerimenti accettati.

### Criterio di uscita

La creazione prodotto è pronta quando può essere considerata una transazione applicativa affidabile e ripetibile, non soltanto un effetto collaterale della review.

---

# PARTE IV — COPERTURE E GARANZIE

## 9. Stato attuale del blocco garanzie

Product Vault possiede già una base migliore di una semplice colonna `warranty_expires_at`:

- model `Warranty` separato;
- tipo di garanzia;
- documento sorgente;
- data inizio e fine;
- durata;
- `source`;
- `confidence_score`;
- metadata;
- regole configurabili;
- creazione idempotente;
- lifecycle events;
- modifica manuale.

Il problema principale non è quindi buttare via l’implementazione esistente.

Il problema è evitare che una regola italiana predefinita di 24 mesi venga percepita come certezza applicabile a ogni acquisto.

Il backend la tratta già come stima calcolata. La prossima fase deve rendere esplicito il contesto e migliorare la comunicazione UI.

---

## 10. Fase 4 — Product Coverage Context

### Obiettivo

Evolvere la garanzia stimata in una copertura contestualizzata, verificabile e modificabile.

### Branch consigliato

```text
pv-product-coverage-context
```

### Principio

Product Vault non deve dichiarare automaticamente:

> Garanzia valida fino al 12 giugno 2028.

Deve poter dichiarare:

> Possibile copertura legale stimata fino al 12 giugno 2028, calcolata usando la data di acquisto e una regola italiana generale. Verifica il tipo di acquisto e la data di consegna.

### Contesto minimo da raccogliere

- acquisto personale o aziendale;
- venditore professionale o privato;
- prodotto nuovo, usato o ricondizionato;
- paese rilevante;
- data acquisto;
- data consegna, se disponibile;
- fonte della data iniziale;
- copertura dichiarata in un documento;
- conferma dell’utente.

### Stati consigliati

- `estimated`;
- `declared`;
- `user_confirmed`;
- `verified`;
- `expired`;
- `cancelled`;
- `unknown`.

Nella prima iterazione questi stati possono essere salvati nei metadata se una migrazione completa sarebbe prematura. Se diventano oggetto di query frequenti, dovranno essere normalizzati in colonne dedicate.

### Tipi di copertura

- legale;
- commerciale del produttore;
- estesa;
- assicurativa;
- copertura derivata da riparazione;
- sconosciuta.

### Modifica consigliata alla UI

Mostrare sempre:

- tipo;
- stato;
- data iniziale;
- data finale;
- fonte;
- motivo del calcolo;
- informazioni mancanti;
- pulsante di conferma o modifica.

### Criterio di uscita

La UI non deve più far sembrare la regola dei 24 mesi una certezza universale.

Il sistema deve distinguere chiaramente stima, dato documentale e conferma utente.

---

# PARTE V — DAL POSSESSO ALL’AZIONE

## 11. Fase 5 — Primo workflow “Ho un problema”

### Obiettivo

Dimostrare che Product Vault non è soltanto un archivio, ma uno strumento che aiuta a risolvere un problema reale.

### Branch consigliato

```text
pv-product-issue-workflow
```

### Vertical slice iniziale

Nella scheda prodotto aggiungere:

```text
Ho un problema con questo prodotto
```

Il primo workflow deve restare essenziale.

### Passaggi

1. descrizione del problema;
2. data di comparsa;
3. stato di utilizzabilità;
4. eventuale danno accidentale dichiarato;
5. allegati fotografici;
6. verifica dei documenti disponibili;
7. riepilogo delle coperture registrate;
8. elenco delle informazioni mancanti;
9. generazione di una bozza di richiesta;
10. registrazione dello stato della pratica.

### Entità consigliata

Una pratica possiede uno stato proprio e non dovrebbe essere rappresentata soltanto da una nota generica.

Esempio concettuale:

```text
ProductCase
- team_id
- product_id
- opened_by_user_id
- type
- status
- title
- description
- occurred_at
- submitted_at
- resolved_at
- outcome
- metadata
```

Stati minimi:

- `draft`;
- `ready`;
- `submitted`;
- `waiting_response`;
- `in_assistance`;
- `resolved`;
- `closed`.

Esiti minimi:

- riparato;
- sostituito;
- rimborsato;
- rifiutato;
- abbandonato;
- altro.

### Prima versione della richiesta

Non serve iniziare con invio automatico, PEC, firma digitale o consulenza legale.

Il primo risultato può essere:

- testo ordinato copiabile;
- riepilogo prodotto;
- riferimenti acquisto;
- descrizione del problema;
- elenco allegati;
- documento PDF esportabile in una fase successiva.

### Criterio di uscita

Il flusso è valido quando almeno un utente può partire da un prodotto confermato e ottenere una richiesta di assistenza completa e tracciabile.

---

## 12. Fase 6 — Dashboard orientata alle azioni

### Obiettivo

Spostare il centro della dashboard da conteggi tecnici a attività concrete.

### Evitare

Una dashboard composta soltanto da:

- numero documenti;
- numero prodotti;
- numero candidati;
- numero garanzie.

### Mostrare invece

#### Da completare

- candidati da revisionare;
- prodotti senza data acquisto;
- coperture stimate da confermare;
- prodotti senza documento sorgente chiaro;
- numero seriale mancante quando utile.

#### In scadenza

- coperture;
- manutenzioni future;
- termini registrati nelle pratiche.

#### Pratiche aperte

- bozza da completare;
- richiesta inviata senza risposta;
- prodotto in assistenza;
- pratica da chiudere.

#### Risultati

- prodotti riparati;
- prodotti sostituiti;
- rimborsi registrati;
- pratiche concluse.

### Criterio di uscita

La dashboard deve rispondere alla domanda:

> Cosa richiede la mia attenzione adesso?

Non soltanto:

> Quanti record contiene il database?

---

# PARTE VI — MONETIZZAZIONE

## 13. Fase 7 — Monetizzazione dopo il primo valore operativo

Non è consigliato monetizzare principalmente lo spazio occupato dai PDF.

Il valore a pagamento deve essere collegato a tempo risparmiato, automazione e risoluzione dei problemi.

### Gratuito

- limite di prodotti;
- upload manuale;
- estrazione base;
- review manuale;
- scheda prodotto;
- copertura stimata;
- promemoria essenziali.

### Premium personale

- più prodotti e documenti;
- importazione da email;
- assisted review avanzata;
- famiglia o condivisione;
- pratiche multiple;
- fascicoli esportabili;
- notifiche avanzate;
- cronologia completa.

### Acquisto singolo

- generazione di un fascicolo assistenza;
- importazione massiva;
- revisione avanzata di una pratica;
- esportazione completa di un prodotto.

### Business

- team;
- assegnazione beni;
- sedi e reparti;
- responsabilità;
- manutenzioni;
- audit log;
- export inventario;
- API e integrazioni.

### Regola

Non implementare pagamenti prima di avere almeno un flusso verticale completato e misurabile.

La prima metrica economica utile non è il numero di documenti caricati, ma:

- pratiche avviate;
- pratiche concluse;
- tempo risparmiato;
- prodotti riparati o sostituiti;
- rimborsi registrati;
- utenti che tornano per gestire un evento successivo all’acquisto.

---

# PARTE VII — FUTURI BATCH

## 14. Quando creare BATCH03

BATCH03 non deve essere il prossimo lavoro automatico.

Deve essere creato soltanto quando esiste una domanda precisa che il batch deve verificare.

Esempi validi:

- documenti reali emersi durante test utenti;
- regressione causata da assisted review;
- documenti che aggiornano prodotti esistenti;
- certificati di garanzia;
- ricevute di riparazione;
- note di credito;
- documenti di consegna;
- documenti con più eventi riferiti allo stesso prodotto;
- documenti con prova d’acquisto ma dati insufficienti per una copertura.

Esempi non validi:

- aggiungere dieci brand nuovi;
- aggiungere categorie molto specifiche;
- cercare un altro formato soltanto per aumentare il numero di fixture;
- adattare il parser a ogni anomalia isolata;
- usare il batch per costruire una knowledge base infinita.

### Possibile obiettivo di BATCH03

Il prossimo batch dovrebbe cambiare dimensione funzionale.

Titolo possibile:

```text
BATCH03 — Documenti post-acquisto e aggiornamento lifecycle
```

Possibili file:

- certificato di garanzia commerciale;
- ricevuta di riparazione;
- documento di sostituzione;
- nota di credito;
- prova di consegna;
- estensione di garanzia;
- documento assicurativo;
- manuale pertinente senza generazione prodotto;
- comunicazione assistenza;
- documento non pertinente simile a una riparazione.

Questo batch dovrebbe verificare:

```text
Il documento crea un prodotto,
aggiorna un prodotto esistente,
o deve essere soltanto archiviato?
```

Non dovrebbe limitarsi a chiedere:

```text
Il parser riconosce un’altra fattura?
```

---

# PARTE VIII — DISCIPLINA DI SVILUPPO

## 15. Branch consigliati

Ordine suggerito:

```text
main

pv-assisted-review-foundation
pv-assisted-review-ui
pv-product-confirmation-hardening
pv-review-signal-language
pv-product-coverage-context
pv-product-issue-workflow
pv-action-dashboard
```

Ogni branch deve avere:

- obiettivo singolo;
- patch piccole;
- test prima e dopo;
- nessuna correzione opportunistica non collegata;
- commit leggibili;
- regressioni BATCH01 e BATCH02 verdi.

---

## 16. Regole per valutare ogni nuova patch

Prima di implementare una modifica, chiedersi:

1. migliora il riconoscimento strutturale o soltanto un caso specifico?
2. riguarda recognition, completion, coverage o lifecycle?
3. il dato è estratto, suggerito, calcolato o confermato?
4. la modifica crea una certezza che il sistema non possiede?
5. l’utente può correggere il risultato?
6. la modifica è idempotente?
7. introduce duplicati?
8. peggiora documenti già stabilizzati?
9. produce valore visibile o soltanto complessità interna?
10. è necessaria per il prossimo flusso verticale?

---

## 17. Cose da non sviluppare adesso

Per mantenere il focus, non sono prioritarie:

- AI obbligatoria per tutti i documenti;
- identificazione universale di brand e modelli;
- marketplace riparatori;
- pareri legali automatici;
- monitoraggio globale dei richiami;
- ricerca automatica universale dei manuali;
- valutazione dell’usato;
- confronto prezzi;
- gestione di ogni tipo di contratto;
- app mobile nativa;
- API pubblica completa;
- decine di piani tariffari;
- deduplica semantica aggressiva.

Queste funzionalità possono essere valutate soltanto dopo che il flusso principale produce valore reale.

---

## 18. Metriche per la prossima fase

### Recognition

- documenti correttamente classificati;
- righe prodotto attese;
- candidati attesi;
- falsi positivi;
- falsi negativi;
- importi coerenti;
- documenti irrilevanti senza prodotti.

Queste metriche restano di regressione.

### Assisted review

- percentuale candidati confermati senza modifica;
- percentuale suggerimenti brand accettati;
- percentuale suggerimenti categoria accettati;
- tempo medio di revisione;
- campi medi richiesti all’utente;
- candidati ignorati;
- modifiche dopo la conferma.

### Product value

- prodotti con documento collegato;
- prodotti con copertura verificata;
- pratiche aperte;
- pratiche concluse;
- tempo tra apertura e risoluzione;
- esito della pratica;
- utenti che tornano dopo il primo caricamento.

---

## 19. Definition of Done del prossimo blocco

Il prossimo blocco, assisted review, è concluso quando:

1. BATCH01 e BATCH02 restano verdi;
2. ogni candidato espone i campi mancanti;
3. brand, categoria e modello possono essere suggeriti senza essere salvati automaticamente;
4. l’utente distingue estratto, suggerito e confermato;
5. i campi opzionali non bloccano la conferma;
6. conferma e ignore sono idempotenti;
7. il prodotto conserva la provenienza dei dati;
8. la UI traduce i segnali tecnici in messaggi comprensibili e orientati all’azione, mantenendo codici, score e soglie soltanto nella diagnostica;
9. la creazione prodotto non genera duplicati;
10. il sistema è pronto per il successivo blocco sulle coperture.

---

## 20. Prossimi passi operativi raccomandati

Il branch attualmente in lavorazione è:

```text
pv-product-confirmation-hardening
```

Il suo obiettivo è:

> Rendere il passaggio Candidate → Product affidabile, ripetibile e tracciabile, trasferendo soltanto dati estratti o confermati, permettendo la conferma con campi opzionali mancanti ed evitando duplicazioni causate da retry o richieste concorrenti.

Ordine interno consigliato:

1. definire la policy di trasferimento dei campi;
2. applicare la policy al creator;
3. rendere non bloccanti i campi opzionali mancanti;
4. allineare la UI al comportamento effettivo;
5. proteggere doppio click, retry e richieste concorrenti;
6. rendere idempotenti prodotto ed effetti collaterali;
7. conservare uno snapshot della provenienza;
8. definire il comportamento durante il reprocessing;
9. verificare BATCH01 e BATCH02.

Dopo la conclusione del confirmation hardening, il branch successivo raccomandato è:

```text
pv-review-signal-language
```

Il suo obiettivo deve essere limitato a:

> Tradurre i segnali tecnici dei candidati in messaggi comprensibili, raggruppati per significato e gravità, mantenendo invariati metadata, soglie e risultati del riconoscimento.

Successivamente si potrà aprire:

```text
pv-product-coverage-context
```

per rendere le coperture stimate trasparenti, contestualizzate e verificabili.

---

## 21. Decisione finale

BATCH02 conclude il primo ciclo di robustezza documentale.

Da questo punto Product Vault non deve dimostrare soltanto di saper leggere documenti. Deve dimostrare di saper trasformare un documento in un prodotto affidabile e il prodotto in un’azione utile.

La direzione è:

```text
BATCH02 stabile
+ assisted review
+ conferma prodotto affidabile
+ coperture trasparenti
+ lifecycle
+ workflow di assistenza
= MVP con valore reale
```

La priorità immediata non è BATCH03.

La priorità immediata è costruire il ponte tra il motore di riconoscimento già consolidato e l’esperienza per cui un utente sarebbe disposto a tornare e, successivamente, a pagare.
