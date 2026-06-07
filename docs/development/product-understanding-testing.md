# Product Understanding — Testing e Smoke Test

Questo documento descrive come testare il livello Product Understanding di Product Vault durante lo sviluppo.

Il Product Understanding non deve sostituire la business logic Laravel. Serve a produrre segnali osservabili e verificabili per aiutare revisione manuale, generazione candidati prodotto e conoscenza progressiva del sistema.

## Comandi principali

### Test completo Product Understanding

```bash
php artisan product-vault:test-understanding