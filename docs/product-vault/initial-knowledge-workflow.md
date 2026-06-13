# Product Vault - Initial Knowledge Workflow

La initial knowledge base di Product Vault serve ad arricchire in modo prudente i candidati prodotto generati dai documenti.

Non è un catalogo prodotti completo e non deve diventarlo.

## Principi

La knowledge base:

* aiuta la revisione manuale, non la sostituisce;
* non crea prodotti;
* non crea global facts;
* non crea feedback utente;
* non inventa EAN, seriali, modelli o brand;
* non conferma automaticamente candidati;
* non blocca automaticamente candidati;
* non modifica score in modo diretto.

Ogni modifica deve essere piccola, verificabile e basata su casi osservati.

## File principali

```
data/product_vault/knowledge/v1/metadata.php
data/product_vault/knowledge/v1/brands.php
data/product_vault/knowledge/v1/brand_aliases.php
data/product_vault/knowledge/v1/line_patterns.php
data/product_vault/knowledge/v1/exclusion_patterns.php
```

## Ciclo operativo corretto

Il ciclo consigliato è:

```
audit
  -> identificazione problemi reali
  -> micro-patch su pattern o alias
  -> test
  -> refresh controllato
  -> nuovo audit
  -> commit
```

## 1. Verifica iniziale

Prima di ogni modifica:

```
php artisan optimize:clear

php artisan product-vault:test-initial-knowledge
php artisan product-vault:test-understanding
php artisan product-vault:test-warranty-lifecycle
```

Se uno di questi comandi fallisce, non modificare il knowledge pack prima di aver capito la regressione.

## 2. Audit read-only

Per vedere come la initial knowledge sta arricchendo i candidati:

```
php artisan product-vault:audit-initial-knowledge --limit=200
```

Per un documento specifico:

```
php artisan product-vault:audit-initial-knowledge --document=ID
```

Per mostrare solo i candidati con match:

```
php artisan product-vault:audit-initial-knowledge --only-matched --limit=200
```

Per controllare solo i fuzzy match:

```
php artisan product-vault:audit-initial-knowledge --only-fuzzy --limit=200
```

Per isolare fuzzy match borderline:

```
php artisan product-vault:audit-initial-knowledge --only-fuzzy --max-similarity=0.85 --limit=200
```

Questi comandi sono diagnostici e non modificano dati.

## 3. Cosa cercare nell'audit

Durante l'audit cercare:

* candidati senza pattern ma che dovrebbero essere prodotti durevoli;
* categorie troppo generiche;
* pattern fuzzy rischiosi;
* brand o alias globali mancanti;
* accessori classificati male;
* prodotti durevoli riconosciuti come semplici accessori;
* righe di servizio, sconto, garanzia o pagamento che non devono diventare prodotto.

Non aggiungere pattern solo "a intuito". Ogni nuovo pattern deve nascere da un caso osservato.

## 4. Patch ammesse

Sono considerate patch sicure:

* aggiunta di un alias brand globale verificato;
* aggiunta di un pattern prodotto osservato più volte;
* correzione di product_kind per un pattern già esistente;
* correzione di categoria quando il caso è chiaro;
* aggiunta di un exclusion pattern prudente per righe non prodotto.

Sono da evitare:

* pattern troppo generici come usb, hdmi, pro, mini, nero, cavo;
* brand fittizi o specifici dei documenti di test;
* categorie troppo granulari create prima di avere abbastanza casi reali;
* modifiche che influenzano scoring, conferme automatiche o UI senza una fase separata.

## 5. Refresh controllato

Prima usare sempre il dry-run:

```
php artisan product-vault:refresh-initial-knowledge --limit=200 --dry-run
```

Oppure su un documento specifico:

```
php artisan product-vault:refresh-initial-knowledge --document=ID --dry-run
```

Se il risultato è coerente:

```
php artisan product-vault:refresh-initial-knowledge --limit=200
```

Oppure:

```
php artisan product-vault:refresh-initial-knowledge --document=ID
```

Il refresh aggiorna solo i metadata initial knowledge e valorizza brand_id / category_id se sono null e se il match è sicuro.

Non deve:

* creare prodotti;
* eliminare candidati;
* cambiare review_status;
* modificare DocumentLine;
* creare global facts;
* creare feedback utente.

## 6. Audit dopo refresh

Dopo il refresh:

```
php artisan product-vault:audit-initial-knowledge --limit=200
php artisan product-vault:audit-initial-knowledge --only-fuzzy --limit=200
php artisan product-vault:audit-initial-knowledge --only-fuzzy --max-similarity=0.85 --limit=200
```

Verificare che:

* i match attesi siano presenti;
* i fuzzy borderline siano leggibili;
* non siano comparsi match rischiosi;
* categorie e product_kind siano coerenti.

## 7. Test finali

Prima del commit:

```
php artisan optimize:clear

php artisan product-vault:test-initial-knowledge
php artisan product-vault:test-understanding
php artisan product-vault:test-warranty-lifecycle
```

Se la patch riguarda solo documentazione, i test possono comunque essere eseguiti per confermare che il ramo resta stabile.

## 8. Commit

Usare commit piccoli e descrittivi.

Esempi:

```
git add data/product_vault/knowledge/v1/line_patterns.php
git commit -m "Refine initial knowledge product kinds"

git add app/Console/Commands/ProductVault/AuditInitialKnowledgeCommand.php
git commit -m "Add fuzzy filters to initial knowledge audit"

git add docs/product-vault/initial-knowledge-workflow.md
git commit -m "Document initial knowledge workflow"
```

## Regola finale

La initial knowledge base deve migliorare la qualità della revisione, non nascondere l'incertezza.

Quando un dato è incerto, Product Vault deve conservarlo come segnale diagnostico e lasciare la decisione finale alla revisione manuale.
