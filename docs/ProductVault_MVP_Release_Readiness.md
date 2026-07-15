# Product Vault — MVP Release Readiness

## Scopo

Questa patch prepara Product Vault a una prima distribuzione pilota controllata.
Non attiva pagamenti, non passa automaticamente la monetizzazione in `enforce` e non trasforma l’MVP in un servizio pubblico già validato legalmente.

Gli obiettivi sono:

- distinguere dati applicativi e workspace assimilabili a fixture;
- verificare configurazione, database, storage, queue e strumenti OCR/PDF;
- controllare piani, limiti e contatori;
- rendere visibili privacy, termini e trattamento dei documenti;
- guidare il primo utilizzo del workspace;
- rendere più sicuro il caricamento in caso di errori;
- avere una smoke suite unica prima di ogni rilascio.

## 1. Audit dei dati di ambiente

Il comando:

```bash
php artisan product-vault:audit-environment-data
```

classifica i workspace come:

- `application`;
- `fixture_like`.

La classificazione usa pattern configurabili in:

```text
config/release_readiness.php
```

Il comando non modifica e non cancella dati.

Per simulare il controllo produzione:

```bash
php artisan product-vault:audit-environment-data --production
```

Se sono presenti workspace assimilabili a fixture e non è stato impostato:

```env
RELEASE_ALLOW_FIXTURE_WORKSPACES=true
```

il comando termina con errore.

L’override deve essere usato soltanto in ambienti pilota deliberatamente misti, non per nascondere una contaminazione dei dati.

## 2. Release readiness inspector

Il comando principale è:

```bash
php artisan product-vault:release-readiness
```

Controlla:

- `APP_KEY`, ambiente, debug, URL e livello log;
- modalità monetizzazione;
- connessione database e tabelle essenziali;
- storage privato e isolamento dal link pubblico;
- queue, tabelle dei job e job falliti;
- Tesseract, Poppler, Python e script PaddleOCR;
- rotte pubbliche e rotte protette;
- catalogo piani, limiti e funzionalità;
- workspace senza piano;
- drift o contatori monetizzazione mancanti;
- workspace assimilabili a fixture;
- configurazione dei documenti legali;
- trasporto email.

Per applicare regole più severe:

```bash
php artisan product-vault:release-readiness --production
```

Per considerare anche i warning come errori:

```bash
php artisan product-vault:release-readiness --production --strict
```

Per integrazioni automatiche:

```bash
php artisan product-vault:release-readiness --json
```

## 3. Esiti

Ogni controllo restituisce uno dei seguenti stati:

- `PASS`: requisito disponibile;
- `WARNING`: condizione da valutare, ma non necessariamente bloccante nel pilota;
- `FAIL`: requisito incompatibile con il profilo richiesto.

In sviluppo sono attesi warning per:

- workspace di test;
- mailer `log`;
- sessione non cifrata;
- Tesseract non disponibile quando PaddleOCR è il motore primario;
- capacità del piano Free raggiunte.

In produzione diventano bloccanti, tra gli altri:

- `APP_DEBUG=true`;
- ambiente diverso da `production` quando si usa il profilo produzione;
- `APP_URL` non HTTPS, se richiesto;
- storage documenti sul disco pubblico;
- queue `sync` o `null`;
- strumenti richiesti non disponibili;
- tabelle mancanti;
- rotte documentali senza autenticazione;
- workspace senza piano;
- fixture non autorizzate;
- indirizzo legale placeholder.

## 4. Configurazione OCR e PDF

La configurazione operativa usa:

```env
OCR_PRIMARY_ENGINE=paddleocr
TESSERACT_BINARY=tesseract
POPPLER_PDFTOPPM_BINARY=pdftoppm
PADDLE_OCR_PYTHON=C:\path\to\python.exe
PADDLE_OCR_SCRIPT=C:\path\to\paddle_ocr_extract.py
```

Se `OCR_PRIMARY_ENGINE=paddleocr`, Python e script PaddleOCR sono requisiti.
Tesseract resta un controllo informativo salvo selezione esplicita come motore primario.

## 5. Sicurezza del caricamento

`StoreUploadedDocumentAction` ora separa due tipi di errore.

### Persistenza iniziale

Documento, media e metering vengono creati dentro una transazione.
Se la persistenza fallisce:

- la transazione viene annullata;
- il file fisico eventualmente creato viene rimosso;
- eventuali record residui vengono eliminati;
- l’eccezione originale viene preservata;
- l’errore di cleanup viene registrato senza nascondere la causa iniziale.

### Dispatch della pipeline

Il job viene inviato soltanto dopo la persistenza.
Se il dispatch fallisce:

- il documento resta disponibile come prova del caricamento;
- stato documento ed estrazione diventano `failed`;
- viene creato un `DocumentProcessingAttempt` con step `dispatch`;
- viene registrata queue, eccezione e file originale;
- l’errore viene rilanciato all’interfaccia.

Questa scelta evita sia record parziali sia la perdita silenziosa di un file già salvato.

## 6. Documenti legali MVP

Sono disponibili le rotte pubbliche:

```text
/privacy
/terms
/document-processing
```

Le pagine descrivono:

- dati e finalità principali;
- responsabilità dell’utente;
- carattere revisionabile di OCR e riconoscimento;
- distinzione tra documento e prodotto;
- natura stimata delle coperture;
- gestione delle pratiche;
- limiti della fase MVP.

I testi sono una base operativa e devono essere revisionati e adattati dal gestore prima di una distribuzione pubblica o commerciale.

Variabili richieste:

```env
LEGAL_EFFECTIVE_DATE=2026-07-15
LEGAL_SUPPORT_EMAIL=support@example.com
LEGAL_CONTROLLER_NAME="Product Vault"
```

L’indirizzo `example.com` è accettato soltanto come placeholder locale e causa un errore nel profilo produzione.

## 7. Guida iniziale

La pagina autenticata:

```text
/account/getting-started
```

mostra sei passaggi:

1. workspace disponibile;
2. piano e capacità verificati;
3. primo documento caricato;
4. prima scheda prodotto confermata;
5. copertura controllata;
6. workflow assistenza provato.

La guida legge i dati reali del workspace e non salva un secondo stato di avanzamento artificiale.

## 8. Smoke suite

Il comando:

```bash
php artisan product-vault:mvp-release-smoke
```

esegue la suite essenziale per:

- monetizzazione;
- guardrail e metering;
- pratiche prodotto;
- coperture;
- dashboard;
- welcome e pagina piano;
- release readiness;
- pagine legali e onboarding;
- contratti di failure safety.

Per includere le regressioni documentali più lunghe:

```bash
php artisan product-vault:mvp-release-smoke --include-documents
```

Per fermarsi al primo errore:

```bash
php artisan product-vault:mvp-release-smoke --stop-on-failure
```

Per mostrare l’output completo dei singoli comandi:

```bash
php artisan product-vault:mvp-release-smoke --show-output
```

## 9. Test dedicati

```bash
php artisan product-vault:test-release-readiness
php artisan product-vault:test-release-legal-ui
php artisan product-vault:test-release-failure-safety
```

Regressioni consigliate:

```bash
php artisan product-vault:test-monetization-observe-hardening
php artisan product-vault:test-product-case-workflow
php artisan product-vault:test-dashboard-action-hierarchy
php artisan product-vault:test-warranty-lifecycle
```

## 10. Checklist pilota

Prima di aprire l’ambiente a utenti reali:

1. configurare un dominio HTTPS;
2. impostare `APP_ENV=production` e `APP_DEBUG=false`;
3. configurare database, backup e restore testato;
4. configurare queue worker e supervisione;
5. verificare strumenti OCR/PDF con l’utente di sistema del server;
6. usare storage privato non esposto da web server;
7. sostituire email e titolare placeholder;
8. revisionare privacy e termini;
9. eliminare o separare fixture e workspace di test;
10. assegnare un piano a ogni workspace reale;
11. sincronizzare contatori;
12. eseguire `release-readiness --production --strict`;
13. eseguire la smoke suite completa;
14. mantenere `MONETIZATION_ENFORCEMENT_MODE=observe` durante il primo pilota.

## 11. Fuori ambito

Questa patch non introduce:

- Stripe o provider di pagamento;
- checkout;
- prezzi definitivi;
- rinnovi e fatturazione;
- consenso legale versionato nel database;
- cancellazione automatica dei workspace fixture;
- automazione dei backup;
- provisioning dell’infrastruttura;
- garanzia di conformità legale.

Questi elementi richiedono decisioni operative e dati reali del pilota.
