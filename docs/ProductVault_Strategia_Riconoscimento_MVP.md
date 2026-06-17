# Product Vault — Strategia MVP per riconoscimento documenti, righe prodotto e conoscenza prodotto

**Versione:** 1.0  
**Data:** 2026-06-17  
**Ambito:** Product Vault / MVP1  
**Scopo:** definire come procedere senza rincorrere conoscenza infinita di brand, categorie e prodotti.

---

## 1. Decisione principale

Product Vault non deve diventare un sistema che prova a conoscere ogni brand, ogni modello e ogni categoria prima di poter funzionare.

La priorità dell’MVP è:

1. riconoscere correttamente il tipo di documento;
2. estrarre righe prodotto reali;
3. escludere righe non prodotto;
4. preservare nome prodotto, quantità, prezzo unitario e totale;
5. creare candidati revisionabili;
6. permettere all’utente di completare ciò che manca.

La conoscenza prodotto — brand, categoria precisa, modello canonico, global facts, normalizzazione avanzata — deve restare un livello separato, utile ma non bloccante.

La promessa dell’MVP non è:

> “Identifichiamo automaticamente ogni prodotto.”

La promessa dell’MVP è:

> “Salviamo la prova d’acquisto, estraiamo ciò che è affidabile e facciamo completare rapidamente solo ciò che manca.”

---

## 2. Problema che vogliamo evitare

Il flusso da evitare è questo:

```text
test documentale
→ mancano brand/categorie/global facts
→ aggiungiamo nuove regole di conoscenza
→ nuovo batch
→ mancano altri brand/categorie/global facts
→ aggiungiamo altra conoscenza
→ ciclo infinito
```

Questo ciclo non scala e non porta online il prodotto.

Ogni nuovo batch reale produrrà sempre nuovi brand, nuovi modelli, nuovi merchant, nuove abbreviazioni e nuove categorie marginali. Cercare di completarli tutti con regole manuali significa trasformare il progetto in una knowledge base infinita, non in un prodotto.

---

## 3. Separazione dei livelli

Da questo punto in avanti il sistema deve essere ragionato su tre livelli separati.

### 3.1 Livello A — Riconoscimento strutturale

Questo è il cuore dell’MVP.

Deve rispondere a domande come:

- Che tipo di documento è?
- È una fattura, uno scontrino, una conferma ordine, un documento non pertinente, una garanzia, un manuale?
- Quali righe sono prodotti?
- Quali righe sono totali, sconti, pagamenti, IVA, spedizione, note, comunicazioni, servizi o consumabili?
- Qual è il nome prodotto leggibile?
- Qual è la quantità?
- Qual è il prezzo unitario?
- Qual è il totale riga?
- Il totale riga è coerente con quantità × prezzo unitario?
- Il documento deve generare candidati o deve essere solo salvato/classificato?

Questo livello deve essere robusto, testato e coperto da regressione.

### 3.2 Livello B — Completezza prodotto

Questo livello riguarda informazioni utili ma non sempre indispensabili:

- brand;
- categoria;
- modello normalizzato;
- canonical name;
- global facts;
- match con conoscenza iniziale;
- match fuzzy;
- deduplica avanzata.

Questi elementi aumentano qualità e affidabilità, ma non devono impedire la creazione di una bozza prodotto se la riga è riconosciuta bene.

### 3.3 Livello C — Arricchimento avanzato / AI

Questo livello può diventare premium o opzionale:

- riconoscimento brand tramite AI;
- categoria più precisa;
- deduplica semantica;
- normalizzazione modello;
- arricchimento scheda prodotto;
- suggerimenti avanzati da web/API/AI;
- creazione automatica di global facts più ricchi.

Questo livello non deve essere necessario per il funzionamento dell’MVP base.

---

## 4. Contratto di qualità MVP

Un candidato prodotto deve essere considerato valido per l’MVP quando soddisfa questi requisiti minimi:

1. il documento sorgente è classificato correttamente;
2. la riga è effettivamente una riga prodotto;
3. la descrizione è comprensibile per un utente;
4. il prezzo è positivo e coerente;
5. la quantità è coerente o ricostruibile;
6. il totale riga è coerente o spiegabilmente mancante;
7. il candidato è revisionabile;
8. non proviene da totale, pagamento, IVA, sconto, spedizione, nota, servizio o documento non pertinente.

Non sono requisiti obbligatori per il candidato MVP:

- brand sempre valorizzato;
- categoria sempre valorizzata;
- global facts sempre presenti;
- modello sempre normalizzato;
- canonical product name perfetto;
- match con knowledge base.

---

## 5. Cosa deve bloccare una patch

Una patch deve essere considerata problematica se introduce uno di questi errori:

- documento classificato nel tipo sbagliato in modo operativo;
- documento non pertinente che genera candidati;
- totale ordine interpretato come prodotto;
- pagamento interpretato come prodotto;
- IVA/imponibile interpretati come prodotto;
- spedizione o servizio interpretati come prodotto durevole;
- consumabile interpretato come prodotto durevole quando il documento è misto;
- riga prodotto reale non estratta;
- quantità tecnica scambiata per quantità acquistata;
- prezzo unitario/totale incoerente;
- numeri tecnici rimossi dal nome prodotto;
- candidati duplicati;
- regressione su batch già stabilizzati.

Esempi di numeri tecnici da preservare:

- `WiFi 6`;
- `1TB`;
- `128GB`;
- `3S`;
- `20000mAh`;
- `100W`;
- `E27`;
- `4K`;
- `27 UHD`.

---

## 6. Cosa non deve bloccare una patch

Non deve bloccare una patch, se il riconoscimento strutturale è corretto:

- `missing_global_facts`;
- brand mancante;
- categoria mancante;
- brand non confermato;
- categoria macro non perfetta;
- canonical name non normalizzato;
- match fuzzy non trovato;
- initial knowledge assente;
- OCR imperfetto ma leggibile e validabile.

Questi casi devono diventare segnali di revisione, non errori di regressione.

---

## 7. Nuova interpretazione dei warning

I warning devono essere divisi in due famiglie.

### 7.1 Warning strutturali

Questi sono importanti e possono bloccare:

- quantità incerta;
- importo incoerente;
- riga candidata debole;
- documento ambiguo;
- possibile falso positivo;
- possibile totale interpretato come prodotto;
- prodotto senza prezzo;
- candidato da documento non pertinente.

### 7.2 Warning di completezza

Questi non devono bloccare l’MVP:

- brand mancante;
- categoria mancante;
- global facts mancanti;
- no initial knowledge;
- no fuzzy match;
- no canonical match.

Devono essere mostrati come:

```text
Da completare in revisione
```

non come:

```text
Problema del parser
```

---

## 8. Brand: strategia corretta

### 8.1 Brand opzionale nell’MVP

Il brand deve essere opzionale.

Se il sistema lo riconosce bene, lo valorizza. Se non lo riconosce, lascia il campo vuoto e lo segnala come completabile.

Non dobbiamo aggiungere continuamente brand alla conoscenza iniziale solo perché appaiono nei test.

Esempi da non rincorrere manualmente:

- `LuxHome`;
- `FitScale`;
- `VoltGo`;
- `NetWave`;
- `AriaDry`;
- `LumioShot`;
- `LumioPrime`;
- `SteadyCam`;
- nuovi brand sintetici o reali emersi nei batch.

### 8.2 Brand suggestion assistita

Il sistema può suggerire possibili brand analizzando la struttura del nome prodotto, senza salvarli automaticamente.

Esempio:

```text
SSD Kingston NV3 1TB
```

Possibile interpretazione:

- `SSD` = tipo prodotto;
- `Kingston` = possibile brand;
- `NV3` = possibile modello;
- `1TB` = specifica tecnica.

Esempio:

```text
Lampada Smart LuxHome E27 WiFi
```

Possibile interpretazione:

- `Lampada` = tipo prodotto;
- `Smart`, `E27`, `WiFi` = attributi;
- `LuxHome` = possibile brand.

Questa logica deve generare suggerimenti, non dati definitivi.

### 8.3 Conferma utente

In revisione, il sistema può mostrare:

```text
Possibile brand: Kingston
[Conferma] [Modifica] [Nessun brand]
```

Il valore confermato dall’utente può alimentare feedback e apprendimento, ma non deve diventare una regola globale aggressiva senza controllo.

---

## 9. Categoria: strategia corretta

Per le categorie non serve una tassonomia infinita.

L’MVP deve usare macro-categorie stabili e comprensibili.

Esempio di tassonomia MVP:

- `computer`;
- `electronics`;
- `home`;
- `small-appliances`;
- `tv-audio`;
- `accessories`;
- `other`;
- `uncategorized`.

La categoria può essere derivata dal tipo prodotto, non dal brand.

Esempi:

- SSD → `computer`;
- router → `computer` o `electronics`;
- mouse → `computer`;
- tastiera → `computer`;
- monitor → `computer`;
- notebook → `computer`;
- powerbank → `electronics`;
- cavo USB-C → `accessories` o `electronics`;
- lampada smart → `home`;
- bilancia smart → `home`;
- deumidificatore → `small-appliances` o `home`;
- robot aspirapolvere → `small-appliances`;
- cuffie → `tv-audio` o `electronics`.

Questa logica è sostenibile perché i tipi prodotto sono molti meno dei brand.

---

## 10. Initial knowledge e global facts

La conoscenza iniziale deve restare piccola, controllata e utile per casi ricorrenti.

Non deve diventare una lista infinita di prodotti di test.

Usi corretti:

- riconoscere famiglie di prodotto ricorrenti;
- migliorare categorie generali;
- supportare prodotti molto comuni;
- valorizzare dati confermati più volte;
- migliorare la revisione, non sostituirla completamente.

Usi da evitare:

- aggiungere un brand solo perché manca in un batch;
- aggiungere un prodotto sintetico solo per far sparire un warning;
- trasformare ogni test in un aggiornamento knowledge;
- far dipendere la qualità del parser dalla knowledge base.

---

## 11. AI e abbonamenti

L’AI deve essere pensata come livello di arricchimento, non come requisito del parser MVP.

### MVP base

- OCR/parsing locale;
- classificazione rule-based;
- righe prodotto;
- quantità/prezzi/totali;
- review manuale;
- categorie macro;
- brand opzionale.

### Piano premium / abbonamento

- riconoscimento brand avanzato;
- categoria più precisa;
- canonical name;
- deduplica semantica;
- suggerimenti modello;
- arricchimento scheda prodotto;
- miglioramento automatico dei campi mancanti.

In questo modo il prodotto può andare online senza dipendere da AI obbligatoria, mantenendo però spazio per monetizzazione futura.

---

## 12. Review UX come parte del prodotto

La review non è un ripiego: è il cuore del flusso MVP.

Schermata ideale per un candidato:

```text
Prodotto trovato:
SSD Kingston NV3 1TB

Prezzo:
64,90 €

Quantità:
1

Documento:
BATCH01_scontrino_durevoli_01.pdf

Campi da completare:
Brand: [Suggerito: Kingston] [Modifica] [Lascia vuoto]
Categoria: [Suggerita: Computer] [Modifica]
Modello: [Suggerito: NV3] [Modifica]

Azioni:
[Conferma prodotto] [Modifica] [Ignora]
```

La UX deve far capire:

- cosa il sistema ha letto con buona confidenza;
- cosa è incerto;
- cosa serve all’utente completare;
- cosa può essere ignorato.

---

## 13. Metriche corrette per i test

I test documentali devono misurare soprattutto riconoscimento strutturale.

Metriche principali:

- document type corretto;
- righe prodotto attese;
- candidati attesi;
- falsi positivi assenti;
- falsi negativi assenti;
- quantità corretta;
- prezzo unitario corretto;
- totale riga corretto;
- amount consistency OK;
- documenti non pertinenti senza candidati;
- consumabili/servizi esclusi quando necessario;
- numeri tecnici preservati.

Metriche secondarie:

- brand presente;
- categoria presente;
- global facts presenti;
- initial knowledge match;
- fuzzy match;
- canonical similarity.

Le metriche secondarie non devono impedire il passaggio della regression MVP, salvo test specifici sul layer knowledge.

---

## 14. Evoluzione dei report

I report devono separare chiaramente:

### Recognition Quality

Questa sezione deve essere usata per decidere se il parser funziona.

Campi:

- documento;
- tipo;
- stato;
- righe prodotto;
- candidati;
- amount consistency;
- false positives;
- false negatives;
- recovery;
- issue strutturali.

### Product Completion

Questa sezione deve indicare cosa manca per completare meglio la scheda.

Campi:

- brand;
- categoria;
- global facts;
- initial knowledge;
- fuzzy;
- suggested brand;
- suggested category;
- needs user review.

Questa separazione evita che un brand mancante venga interpretato come errore del parser.

---

## 15. Strategia per i prossimi batch

Ogni nuovo batch deve essere costruito per testare un problema strutturale, non per aggiungere conoscenza prodotto.

Esempi di batch utili:

- receipt con totale e pagamento vicini a righe prodotto;
- receipt misto con consumabili e durevoli;
- invoice tabellare con righe multilinea;
- order confirmation con importi standalone;
- OCR ruotato/sfocato;
- documento non pertinente con parole fiscali negative;
- prodotti con numeri tecnici nel nome;
- quantità esplicita e quantità implicita;
- spedizione/sconto/servizio da escludere;
- righe con EAN/codice prodotto;
- righe con descrizione povera ma prezzo corretto.

Ogni batch deve chiedere:

```text
Il sistema ha riconosciuto correttamente struttura, righe e importi?
```

Non:

```text
Il sistema conosce tutti i brand?
```

---

## 16. Roadmap operativa consigliata

### Fase 1 — Consolidamento recognition quality

Obiettivo:

- rendere stabile il contratto di riconoscimento;
- separare warning strutturali e warning di completezza;
- aggiornare report e regression per non confondere knowledge gaps con parser issues.

Deliverable:

- documento strategico;
- report aggiornato;
- regression batch stabile;
- definizione di cosa è blocco e cosa è completamento.

### Fase 2 — Assisted review backend

Obiettivo:

- preparare suggerimenti per brand/categoria/modello senza salvarli automaticamente.

Deliverable:

- metadata `assisted_review`;
- suggerimenti brand;
- suggerimenti categoria macro;
- suggerimenti modello;
- flag `needs_user_completion`.

### Fase 3 — UI review guidata

Obiettivo:

- permettere all’utente di confermare rapidamente i campi mancanti.

Deliverable:

- drawer candidato migliorato;
- chip per brand/categoria/modello;
- conferma/modifica/lascia vuoto;
- salvataggio coerente nel prodotto finale.

### Fase 4 — Learning da feedback

Obiettivo:

- usare le conferme utente per migliorare suggerimenti futuri.

Deliverable:

- feedback sui campi confermati;
- riuso prudente per account/team;
- eventuale promozione a global fact solo con criteri controllati.

### Fase 5 — AI opzionale/premium

Obiettivo:

- arricchire automaticamente dove il parser base non può arrivare.

Deliverable:

- provider AI opzionale;
- suggerimenti brand/categoria/canonical name;
- differenza chiara tra campo estratto, suggerito e confermato.

---

## 17. Criteri di uscita per andare online

Product Vault può andare online in una prima versione quando:

1. upload e parsing sono stabili;
2. i documenti vengono salvati sempre;
3. i documenti non pertinenti non generano prodotti;
4. le prove d’acquisto generano candidati solo quando ha senso;
5. quantità/prezzi/totali sono affidabili;
6. la review permette di correggere rapidamente;
7. brand e categorie mancanti non bloccano il flusso;
8. la UI comunica chiaramente cosa è estratto, suggerito o da confermare;
9. i batch di regressione strutturale sono verdi;
10. il sistema non promette identificazione automatica totale.

---

## 18. Decisione finale

La direzione corretta non è costruire una knowledge base infinita.

La direzione corretta è:

```text
riconoscimento strutturale robusto
+ candidati prodotto affidabili
+ revisione manuale guidata
+ categorie macro sostenibili
+ brand opzionale/suggerito
+ AI opzionale per arricchimento avanzato
```

Da ora in avanti, ogni patch deve essere valutata con questa domanda:

> Migliora il riconoscimento strutturale o sta solo aggiungendo conoscenza puntuale?

Se migliora il riconoscimento strutturale, è una patch utile per l’MVP.

Se aggiunge solo un brand, un modello o una categoria specifica per far passare un test, va evitata salvo casi molto motivati.

---

## 19. Checklist per le prossime discussioni tecniche

Prima di proporre una patch chiedersi:

- Questa modifica migliora il parser o aggiunge conoscenza puntuale?
- Il problema è strutturale o è solo completezza prodotto?
- Il candidato sarebbe utile anche senza brand?
- La categoria può essere macro?
- Il dato deve essere automatico o suggerito all’utente?
- Questa regola può generalizzare a nuovi documenti?
- Rischia di creare falsi positivi?
- Rischia di escludere prodotti validi?
- Deve bloccare la regression o solo generare un warning di review?
- È coerente con la promessa MVP?

---

## 20. Glossario operativo

### Riconoscimento strutturale

Capacità del sistema di capire documento, righe, importi, quantità e candidati.

### Completezza prodotto

Livello di dettaglio della scheda prodotto: brand, categoria, modello, facts.

### Candidato affidabile

Candidato con nome comprensibile, prezzo e quantità coerenti, collegato a un documento corretto.

### Candidato incompleto

Candidato affidabile ma con brand/categoria/model/facts mancanti.

### Knowledge gap

Dato mancante nel layer conoscenza. Non equivale a errore del parser.

### Assisted review

Flusso in cui il sistema propone opzioni e l’utente conferma/corregge.

### Global facts

Conoscenza condivisa e riutilizzabile. Deve essere promossa con cautela, non generata da ogni caso isolato.

### AI enrichment

Layer opzionale che migliora completezza e normalizzazione, ma non sostituisce il parser MVP.

---

## 21. Nota di prodotto

Product Vault deve evitare due estremi:

1. creare prodotti falsi o sporchi per eccesso di automazione;
2. bloccarsi perché non conosce ogni brand o categoria.

La via corretta è nel mezzo:

```text
salvare sempre il documento,
estrarre ciò che è affidabile,
generare candidati quando ha senso,
chiedere all’utente solo ciò che manca.
```

Questa deve essere la linea guida per portare il progetto online.
