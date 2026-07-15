# Product Vault — Monetization Foundation

## Scopo

Questa fase prepara Product Vault alla monetizzazione senza introdurre ancora checkout, addebiti, rinnovi o dipendenze da un provider di pagamento.

L’obiettivo è rendere misurabili e configurabili:

- piano del workspace;
- funzionalità incluse;
- limiti quantitativi;
- utilizzo corrente;
- eventi operativi rilevanti;
- valore prodotto dalle pratiche;
- possibili offerte in abbonamento e una tantum.

## Catalogo dei piani

Il catalogo iniziale contiene:

- `free`;
- `premium_personal`;
- `family`;
- `business`.

I prezzi restano intenzionalmente a zero o non definiti. Il catalogo serve a validare segmenti, limiti e differenze funzionali prima dell’integrazione di un sistema di pagamento.

## Limiti configurati

Il contratto condiviso usa le seguenti chiavi:

- `max_documents`;
- `max_products`;
- `max_storage_mb`;
- `max_ocr_per_month`;
- `max_team_members`;
- `max_open_product_cases`.

Un valore `null` indica un limite illimitato. I limiti mensili usano il periodo solare corrente.

## Funzionalità

Le funzionalità sono separate dai limiti numerici nella tabella `plan_features`.

Questo evita di rappresentare capacità qualitative con numeri artificiali e permette di distinguere, tra le altre:

- upload manuale;
- estrazione base;
- review manuale;
- assisted review avanzata;
- importazione da email;
- workspace condiviso;
- pratiche multiple;
- esportazione fascicolo assistenza;
- notifiche avanzate;
- cronologia completa;
- funzioni business e API.

## Misurazione dell’utilizzo

La fondazione usa due livelli distinti:

1. `usage_events`: registro immutabile e idempotente degli eventi;
2. `usage_counters`: proiezioni compatte, sincronizzabili dai dati autorevoli.

Gli snapshot usati per applicare i limiti permanenti non dipendono soltanto dai contatori. Documenti, prodotti, storage, membri e pratiche aperte vengono ricalcolati dalle rispettive tabelle di dominio.

Gli eventi registrati includono:

- documento caricato;
- byte aggiunti allo storage;
- esecuzione OCR;
- prodotto creato;
- pratica aperta;
- pratica risolta;
- pratica chiusa.

Ogni evento richiede una chiave di idempotenza per evitare doppi conteggi in caso di retry.

## Modalità observe ed enforce

La variabile:

```env
MONETIZATION_ENFORCEMENT_MODE=observe
```

ammette due valori:

- `observe`: calcola e mostra i superamenti, ma non blocca il flusso;
- `enforce`: impedisce una nuova operazione quando il consumo previsto supera il limite.

La modalità consigliata per validazione e test utenti è `observe`.

Passare a `enforce` soltanto dopo aver verificato:

- correttezza dei dati;
- assegnazione dei piani;
- contatori e snapshot;
- messaggi mostrati all’utente;
- comportamento di upgrade o richiesta assistenza.

## Guardrail applicativi

I limiti sono collegati ai punti di ingresso principali:

- upload documento;
- creazione prodotto;
- esecuzione OCR;
- apertura pratica prodotto;
- invito di membri nel workspace.

In `observe` questi guardrail producono decisioni diagnostiche senza bloccare. In `enforce` sollevano un errore di dominio o di validazione leggibile.

## Piano e utilizzo

La pagina:

```text
/account/plan
```

mostra:

- piano corrente;
- modalità di applicazione;
- utilizzo e limiti;
- funzionalità incluse;
- metriche di valore operativo;
- catalogo dei piani;
- catalogo delle offerte una tantum.

La pagina non contiene pulsanti di acquisto e dichiara esplicitamente che checkout e pagamenti non sono attivi.

## Metriche di valore

Le metriche iniziali sono:

- pratiche avviate;
- pratiche concluse;
- pratiche annullate;
- riparazioni, sostituzioni e rimborsi;
- distribuzione degli esiti;
- tempo medio di risoluzione;
- utenti che hanno aperto più di una pratica;
- tasso di completamento.

Queste metriche servono a validare il valore del prodotto. Non influenzano automaticamente decisioni su garanzie, coperture o pratiche.

## Offerte una tantum

Il catalogo informativo iniziale comprende:

- fascicolo assistenza;
- importazione massiva;
- revisione avanzata pratica;
- esportazione completa prodotto.

Prezzi, checkout e concessione automatica dell’accesso restano fuori da questa fase.

## Comandi operativi

Dopo migration e seeding:

```bash
php artisan product-vault:sync-monetization-usage
php artisan product-vault:audit-monetization
```

È possibile limitare entrambi a un workspace:

```bash
php artisan product-vault:sync-monetization-usage --team=1
php artisan product-vault:audit-monetization --team=1
```

## Installazione locale

```bash
php artisan migrate
php artisan db:seed --class=PlanSeeder
php artisan optimize:clear
php artisan product-vault:sync-monetization-usage
php artisan product-vault:audit-monetization
```

## Test dedicati

```bash
php artisan product-vault:test-monetization-foundation
php artisan product-vault:test-monetization-usage-guard
php artisan product-vault:test-monetization-domain-metering
php artisan product-vault:test-monetization-value-metrics
php artisan product-vault:test-monetization-overview-ui
```

## Limiti intenzionali

Questa fase non introduce:

- Stripe, PayPal o altri provider;
- checkout;
- webhook di pagamento;
- rinnovi e cancellazioni di abbonamento;
- fatturazione;
- tasse o gestione IVA;
- cambio piano self-service;
- concessione automatica di acquisti singoli;
- pricing definitivo;
- paywall aggressivi.

Questi elementi devono essere progettati soltanto dopo la validazione del catalogo, delle metriche e della modalità `observe`.
