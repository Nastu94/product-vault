# Product Vault - Product Understanding

Questo documento descrive la prima versione del sistema Product Understanding di Product Vault.

Product Understanding è il blocco che prova a trasformare testo estratto da documenti, OCR, righe prodotto, EAN, seriali e feedback utente in candidati prodotto revisionabili.

Il suo obiettivo non è identificare automaticamente ogni prodotto in modo perfetto. L'obiettivo corretto è aiutare l'utente a confermare più velocemente i prodotti, riducendo inserimenti manuali e aumentando progressivamente la conoscenza del sistema.

## Principio guida

Product Vault non deve inventare prodotti.

Quando i dati sono forti, il sistema può suggerire un candidato con alta affidabilità. Quando i dati sono deboli, deve mostrare il candidato come informazione da revisionare, non come verità.

La revisione utente è parte del sistema, non un fallback secondario.

## Ruolo nel flusso MVP

Il Product Understanding entra in gioco dopo:

1. upload documento;
2. estrazione testo;
3. classificazione documento;
4. parsing righe;
5. generazione candidati prodotto.

Il risultato del Product Understanding viene salvato nei metadata del candidato e usato per:

* mostrare segnali nella UI;
* ordinare o evidenziare candidati;
* suggerire conferma o cautela;
* alimentare feedback e global facts dopo la revisione;
* migliorare i caricamenti futuri.

## Entità principali

### Document

Il documento è la fonte originale.

Può essere uno scontrino, una fattura, una conferma ordine, un PDF scansionato, una foto OCR, un documento non pertinente o un documento ancora classificato male.

Il documento non coincide con il prodotto.

### DocumentLine

La riga documento è il risultato del parsing.

Può rappresentare una riga prodotto, uno sconto, una tassa, un pagamento, un totale, una riga informativa o una riga incerta.

Solo alcune righe possono generare candidati prodotto.

### ProductIdentificationCandidate

Il candidato è una proposta generata dal sistema.

È una bozza revisionabile, non ancora un prodotto affidabile.

Può contenere:

* nome candidato;
* modello;
* EAN;
* seriale;
* prezzo;
* quantità;
* riferimento alla riga documento;
* metadata tecnici;
* segnali Product Understanding;
* stato revisione.

Stati principali:

* `pending`;
* `confirmed`;
* `ignored`.

### Product

Il prodotto viene creato o collegato dopo conferma del candidato.

Da quel momento diventa una scheda prodotto dell'utente/workspace.

### Feedback workspace

Il feedback workspace rappresenta ciò che un team/account ha confermato o ignorato.

Serve per migliorare i suggerimenti futuri nello stesso workspace.

Esempio:

* l'utente conferma spesso un certo tipo di riga come prodotto registrabile;
* l'utente ignora spesso accessori minori o righe non utili;
* il sistema può usare questi segnali per suggerire bias positivi o negativi.

### Global facts

I global facts rappresentano conoscenza aggregata e riutilizzabile.

Sono particolarmente importanti quando esiste un identificatore forte, come EAN.

Un global fact può contenere:

* EAN;
* canonical name;
* categoria suggerita;
* line type suggerito;
* conteggi di conferme;
* conteggi di ignore;
* registration rate globale;
* confidence score globale;
* segnali aggregati.

I global facts non devono essere trattati come verità assoluta. Sono conoscenza utile, da pesare con altri segnali.

## Fonti di segnale

Il Product Understanding usa più segnali, che hanno peso diverso.

### EAN

L'EAN è uno dei segnali più forti.

Quando è presente e valido, può collegare candidati diversi allo stesso prodotto canonico.

Esempio:

* documento A: `Docking Station USB-C Duat HOMI 4K`
* documento B: `Docking Station USB-C Dual HDMI 4K`
* stesso EAN: `8055555012222`

Il sistema può capire che si tratta probabilmente dello stesso prodotto, anche se il testo OCR contiene errori.

### Seriale

Il seriale è utile per identificare l'unità specifica posseduta dall'utente.

È meno adatto a creare conoscenza globale, perché due utenti possono avere lo stesso modello ma seriali diversi.

Il seriale è importante per:

* scheda prodotto;
* assistenza;
* garanzia;
* documenti di riparazione;
* prove di possesso.

### Nome prodotto

Il nome prodotto è un segnale utile ma fragile.

Può contenere:

* abbreviazioni;
* errori OCR;
* codici interni merchant;
* pezzi di descrizione;
* numeri modello;
* varianti colore;
* quantità o prezzo interpretati male.

Per questo motivo non va usato da solo come prova forte.

### Modello

Il modello è spesso più identificativo del nome generico.

Esempio:

* `ThinkPad X1 Carbon Gen 11`
* `WH-1000XM5`
* `MX Mechanical Mini`

Un conflitto tra modelli simili deve generare cautela, anche se la similarità testuale è alta.

### Prezzo

Il prezzo aiuta a capire se una riga è coerente, ma non identifica il prodotto.

È utile per:

* distinguere prodotto principale da accessorio;
* verificare quantità/prezzo/totale;
* rilevare righe non prodotto;
* migliorare confidence di parsing.

### Categoria e line type

Categoria e line type aiutano a distinguere prodotti registrabili da righe meno rilevanti.

Esempi:

* notebook: durable product;
* docking station: accessory;
* garanzia estesa: warranty;
* coupon: discount;
* spedizione: service;
* tassa: tax.

## Metadata candidato

Il Product Understanding salva informazioni nei metadata del candidato.

Questi metadata servono sia alla UI sia al debug.

### Feedback matcher

Chiave principale:

* `product_understanding_feedback`

Campi rilevanti:

* `product_identity_score`;
* `registration_preference_score`;
* `suggested_bias`;
* `review_hint`;
* `identity_signals`;
* `preference_signals`.

Questo blocco descrive quanto il candidato assomiglia a cose già viste, confermate o ignorate.

### Global fact matcher

Chiave/versione logica:

* `product_understanding_global_fact_matcher_v1`

Campi rilevanti:

* `matched`;
* `match_type`;
* `seen_count`;
* `confirmed_count`;
* `ignored_count`;
* `global_registration_rate`;
* `suggested_category`;
* `suggested_line_type`;
* `canonical_name`;
* `candidate_normalized_name`;
* `canonical_normalized_name`;
* `candidate_canonical_name_similarity`;
* `global_product_confidence_score`;
* `signals`.

Questo blocco descrive il rapporto tra candidato e conoscenza globale.

### Python analysis

L'analyzer Python aggiunge un livello di similarità testuale più flessibile.

Campi concettuali rilevanti:

* best match;
* canonical name suggerito;
* similarity score;
* metodo di matching;
* confidence;
* warning;
* guardrail;
* model conflict;
* spec difference;
* OCR variant;
* high similarity;
* weak/no global facts.

L'output Python è utile, ma non deve dominare sugli altri segnali.

## Analyzer Python

Il file principale è:

```
tools/product_understanding/analyze_product_text.py
```

L'analyzer usa RapidFuzz per confrontare il nome candidato con nomi canonici o contesto globale.

La versione attuale è basata su:

* token set ratio;
* token sort ratio;
* partial ratio;
* WRatio.

Il servizio PHP invoca l'analyzer e usa il risultato per arricchire i metadata del candidato.

## Perché serve Python

PHP gestisce bene pipeline, persistenza, business logic e UI.

Python viene usato per attività dove è più pratico sperimentare algoritmi testuali:

* similarità fuzzy;
* normalizzazione più evoluta;
* confronto tra varianti OCR;
* ranking di nomi canonici;
* futuri guardrail lessicali.

La scelta non deve trasformare Python in un secondo backend parallelo. Python deve restare uno strumento specializzato e controllato.

## Problema noto: similarity troppo permissiva

Attualmente l'analyzer Python può proporre best match deboli, intorno al 35-40%, verso prodotti senza relazione evidente.

Questo è rischioso perché un match debole può sembrare più significativo di quanto sia.

Per ora la UI nasconde o declassa questi match, mostrandoli solo come diagnostica.

Il problema però resta a monte.

Possibili correzioni future:

* soglia minima sotto cui non restituire `best_match`;
* token overlap obbligatorio;
* blocco se non esistono token informativi condivisi;
* penalizzazione di match basati solo su parole comuni;
* distinzione tra `diagnostic_match` e `usable_match`;
* guardrail per categorie incompatibili;
* guardrail per brand incompatibili;
* guardrail per modello incompatibile.

## Guardrail attuali

I guardrail servono a evitare che una similarità alta generi fiducia eccessiva.

### Model conflict

Due prodotti possono essere molto simili ma avere modello diverso.

Esempio:

* `ThinkPad X1 Carbon Gen 10`
* `ThinkPad X1 Carbon Gen 11`

La similarità testuale può essere alta, ma il modello diverso è un segnale importante.

Il sistema deve mostrare cautela.

### Spec difference

Due prodotti possono condividere brand e linea, ma differire per specifiche.

Esempi:

* memoria diversa;
* capacità diversa;
* colore diverso;
* generazione diversa;
* numero porte diverso;
* versione diversa.

Queste differenze non sempre impediscono il match, ma devono abbassare la fiducia.

### OCR variant

Una variante OCR può generare testo leggermente sbagliato.

Esempio:

* `Duat HOMI`
* `Dual HDMI`

In questi casi il sistema può considerare la similarità come positiva, soprattutto se EAN o altri segnali forti confermano.

### Weak/no global facts

Se non esistono global facts o se i global facts sono deboli, il sistema deve evitare suggerimenti troppo assertivi.

La UI può mostrare il dato come diagnostico, ma non come conoscenza affidabile.

## Interpretazione dei segnali

Nessun segnale singolo è sempre sufficiente.

Una regola pratica:

* EAN valido + global fact forte: alta affidabilità;
* EAN valido + nome rumoroso: affidabilità buona, ma nome da verificare;
* nome simile + modello diverso: cautela;
* similarità Python debole: solo diagnostica;
* feedback workspace coerente: suggerimento utile;
* feedback ignorato frequente: suggerire cautela o bias negativo;
* seriale presente: utile per prodotto posseduto, non per global fact generale;
* prezzo coerente: rafforza la riga, non l'identità prodotto.

## UI e Product Understanding

La UI non deve mostrare tutti i metadata come se fossero uguali.

Deve distinguere tra:

* segnali utili per l'utente;
* segnali diagnostici;
* warning attivi;
* snapshot storici;
* conoscenza attuale.

### Badge conoscenza

I badge sintetizzano il rapporto tra candidato e conoscenza disponibile.

Devono aiutare l'utente a capire rapidamente se il candidato:

* ha EAN;
* ha global fact;
* ha feedback precedente;
* ha warning Python;
* ha match debole;
* richiede revisione.

### Drawer dettaglio conoscenza

Il drawer Revisioni mostra:

* candidato;
* origine documento;
* global fact attuale;
* snapshot global fact salvato sul candidato;
* feedback;
* Python analysis;
* guardrail identità;
* segnali aggregati;
* metadata tecnici.

La distinzione tra snapshot e conoscenza attuale è importante.

Un candidato poteva non avere global facts quando è stato generato, ma può averli dopo la conferma. In quel caso `missing_global_facts` non deve più essere mostrato come warning attivo.

## Snapshot storico vs conoscenza attuale

I metadata del candidato sono uno snapshot del momento in cui il candidato è stato generato o analizzato.

La knowledge base attuale può cambiare dopo:

* conferma candidato;
* ignore candidato;
* creazione global fact;
* aggiornamento conteggi globali;
* nuove conferme in altri documenti;
* nuove fixture o seed.

Quindi la UI deve distinguere:

* cosa il candidato "sapeva" al momento della generazione;
* cosa il sistema sa ora.

Questa distinzione evita warning falsi o messaggi contraddittori.

## Feedback loop

Il flusso di apprendimento attuale è:

1. il sistema genera un candidato;
2. Product Understanding arricchisce i metadata;
3. l'utente conferma o ignora;
4. il sistema registra feedback;
5. eventuali global facts vengono creati o aggiornati;
6. i futuri candidati possono usare questa conoscenza.

Questo loop è il cuore del prodotto.

Product Vault non deve dipendere solo da regole statiche. Deve migliorare man mano che aumentano documenti, conferme, ignore e global facts.

## Comandi dedicati

Seed conoscenza sintetica:

```
php artisan product-vault:seed-understanding-knowledge
```

Esecuzione fixture:

```
php artisan product-vault:run-understanding-fixtures
```

Test completo:

```
php artisan product-vault:test-understanding
```

## Fixture Product Understanding

Le fixture attuali coprono:

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

Le fixture non devono essere viste come prova che il sistema sia completo. Servono a impedire regressioni sugli scenari già conosciuti.

## Esempi di comportamento atteso

### EAN forte

Se un candidato ha EAN già visto e global fact coerente, il sistema può mostrare un segnale forte.

Esempio concettuale:

* candidato: `Docking Station USB-C Duat HOMI 4K`
* global canonical name: `Docking Station USB-C Dual HDMI 4K`
* EAN: uguale
* OCR variant: probabile
* risultato UI: conoscenza utile, nome da verificare ma match forte.

### Modello simile ma diverso

Esempio concettuale:

* candidato: `Notebook Lenovo ThinkPad X1 Carbon Gen 10`
* best match: `Notebook Lenovo ThinkPad X1 Carbon Gen 11`

Anche con similarità alta, il sistema deve segnalare conflitto modello.

Risultato corretto:

* non trattare come stesso prodotto senza revisione;
* mostrare warning o cautela;
* evitare canonicalizzazione automatica aggressiva.

### Match debole

Esempio concettuale:

* candidato generico o rumoroso;
* similarity 35-40%;
* pochi token utili condivisi;
* nessun EAN;
* nessun global fact forte.

Risultato corretto:

* non mostrare come match utile;
* mantenerlo al massimo come dato diagnostico;
* chiedere revisione utente.

## Confini del sistema

Product Understanding non deve:

* creare prodotti senza conferma quando i dati sono incerti;
* sovrascrivere nomi prodotto con best match deboli;
* trattare similarity Python come verità;
* ignorare conflitti di modello o specifica;
* confondere seriale con EAN;
* usare feedback di ignore come prova assoluta che un prodotto non sia utile;
* trasformare Product Vault in un catalogo pubblico non controllato.

Product Understanding deve:

* proporre candidati;
* spiegare perché un candidato sembra affidabile o rischioso;
* conservare metadata diagnostici;
* imparare da conferme e ignore;
* migliorare la revisione;
* ridurre lavoro manuale senza nascondere incertezza.

## Backlog specifico Product Understanding

### P0 - Correzioni importanti

* Ridurre o sopprimere best match Python sotto soglia.
* Introdurre token overlap minimo.
* Separare match diagnostico da match usabile.
* Evitare che parole comuni generino similarità apparente.
* Rafforzare guardrail su modello e specifiche.

### P1 - Miglioramenti utili

* Migliorare normalizzazione nomi prodotto.
* Rafforzare confronto brand/modello.
* Usare categoria e line type come vincoli, non solo come metadata.
* Migliorare gestione OCR variant.
* Migliorare spiegazioni UI dei segnali.
* Ridurre duplicazione tra DocumentShow e ReviewIndex.

### P2 - Evoluzione futura

* Knowledge pack iniziale installabile su database pulito.
* Import controllato di categorie, brand, alias e prodotti comuni.
* Versionamento della knowledge base.
* Tool di amministrazione per correggere global facts.
* Metriche su conferme, ignore e falsi match.
* Valutazione automatica della qualità dei global facts.

## Decisione strategica

La knowledge base iniziale sarà uno dei prossimi blocchi più importanti.

Senza conoscenza iniziale, Product Vault rischia di sembrare utile solo dopo molti caricamenti manuali. Per un servizio gratuito o freemium, questo è un problema: l'utente deve percepire valore già nei primi documenti.

La direzione corretta è costruire un sistema che possa iniettare conoscenza base in modo controllato, revisionabile e testabile, senza dipendere da dati inventati o da match deboli.