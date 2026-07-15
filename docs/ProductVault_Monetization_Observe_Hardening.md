# Product Vault — Monetization Observe Hardening

## Scopo

Questa fase consolida la fondazione di monetizzazione prima di introdurre pagamenti o blocchi reali.

La modalità raccomandata resta:

```env
MONETIZATION_ENFORCEMENT_MODE=observe
```

In questa modalità Product Vault:

- calcola utilizzo e limiti;
- distingue capacità quasi esaurita, esaurita e superata;
- mostra avvisi leggibili all’utente;
- non blocca upload, OCR, prodotti, inviti o pratiche;
- raccoglie dati per validare i limiti prima dell’attivazione di `enforce`.

## Messaggi applicativi

Il componente globale `PlanUsageNotice` compare nelle pagine autenticate quando almeno una capacità è:

- `warning`: utilizzo sopra la soglia configurata;
- `exhausted`: utilizzo uguale al limite;
- `exceeded`: utilizzo superiore al limite.

La pagina `/account/plan` mostra il dettaglio completo e differenzia chiaramente:

- monitoraggio;
- limiti applicati;
- capacità disponibile;
- quasi esaurita;
- esaurita;
- superata;
- illimitata;
- non configurata.

## Assegnazione controllata dei piani

Il comando:

```bash
php artisan product-vault:assign-workspace-plan 2 premium_personal
```

esegue soltanto un’anteprima.

Per applicare:

```bash
php artisan product-vault:assign-workspace-plan 2 premium_personal --apply --actor=1
```

Se l’utilizzo corrente supera uno o più limiti del piano target, l’assegnazione viene rifiutata.

L’override richiede una scelta esplicita:

```bash
php artisan product-vault:assign-workspace-plan 2 free --apply --force --actor=1
```

Ogni modifica applicata:

- aggiorna `teams.plan_id`;
- sincronizza i contatori;
- crea un audit log `workspace.plan_changed`;
- registra piano precedente, piano target, actor e uso incompatibile.

## Diagnostica amministrativa

Il comando:

```bash
php artisan product-vault:diagnose-monetization
```

controlla:

- piano presente e attivo;
- contratto completo di limiti;
- contratto completo di funzionalità;
- contatori mancanti;
- drift tra contatori e dati autorevoli;
- capacità quasi esaurite, esaurite o superate.

Per un singolo workspace:

```bash
php artisan product-vault:diagnose-monetization --team=2
```

La modalità strict restituisce errore anche in presenza di semplici warning:

```bash
php artisan product-vault:diagnose-monetization --strict
```

Per correggere contatori mancanti o non allineati:

```bash
php artisan product-vault:sync-monetization-usage
```

## Welcome page

La pagina pubblica ora espone il catalogo reale dei piani configurati:

- Free;
- Premium personale;
- Famiglia;
- Business.

Mostra inoltre:

- capacità principali;
- funzionalità incluse;
- servizi una tantum previsti;
- assenza di checkout e pagamenti;
- modalità di validazione dei limiti.

Il catalogo viene letto tramite `PlanCatalogResolver`, evitando una seconda definizione manuale dei piani nella pagina marketing.

## Test dedicati

```bash
php artisan product-vault:test-monetization-observe-hardening
php artisan product-vault:test-welcome-monetization
php artisan product-vault:test-monetization-overview-ui
```

Regressioni consigliate:

```bash
php artisan product-vault:test-monetization-foundation
php artisan product-vault:test-monetization-usage-guard
php artisan product-vault:test-monetization-domain-metering
php artisan product-vault:test-monetization-value-metrics
php artisan product-vault:test-product-case-workflow
php artisan product-vault:test-dashboard-action-hierarchy
```

## Criteri prima di attivare enforce

Non impostare `MONETIZATION_ENFORCEMENT_MODE=enforce` finché non sono verificati:

1. assegnazione corretta del piano a tutti i workspace reali;
2. assenza di drift nei contatori;
3. messaggi comprensibili nella UI;
4. limiti realistici rispetto all’uso osservato;
5. gestione esplicita di upgrade, supporto o capacità liberata;
6. test dei flussi bloccati senza perdita di documenti o dati;
7. processo operativo per correggere assegnazioni errate.

## Fuori ambito

Questa fase non introduce:

- Stripe o altri provider;
- checkout;
- rinnovi;
- fatturazione;
- IVA;
- cambio piano self-service;
- prezzi definitivi;
- entitlement acquistati automaticamente.
