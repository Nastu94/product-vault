<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Modalità di applicazione dei limiti
    |--------------------------------------------------------------------------
    |
    | observe: calcola e mostra i superamenti senza bloccare il flusso.
    | enforce: blocca le nuove operazioni quando il limite è esaurito.
    |
    */
    'enforcement_mode' => env(
        'MONETIZATION_ENFORCEMENT_MODE',
        'observe'
    ),

    'warning_threshold_percent' => (int) env(
        'MONETIZATION_WARNING_THRESHOLD_PERCENT',
        80
    ),

    /*
    |--------------------------------------------------------------------------
    | Offerte una tantum
    |--------------------------------------------------------------------------
    |
    | Il catalogo è informativo. Checkout, prezzi e concessione automatica
    | dell'entitlement verranno aggiunti soltanto dopo la validazione.
    |
    */
    'one_time_offers' => [
        [
            'code' => 'assistance_dossier',
            'name' => 'Fascicolo assistenza',
            'description' => 'Esportazione ordinata di prodotto, prova d’acquisto, coperture, problema, allegati e cronologia della pratica.',
            'price_cents' => null,
            'currency_code' => 'EUR',
        ],
        [
            'code' => 'mass_import',
            'name' => 'Importazione massiva',
            'description' => 'Acquisizione assistita di un archivio iniziale di documenti e prodotti.',
            'price_cents' => null,
            'currency_code' => 'EUR',
        ],
        [
            'code' => 'advanced_case_review',
            'name' => 'Revisione avanzata pratica',
            'description' => 'Controllo approfondito della completezza di una pratica prima del contatto esterno.',
            'price_cents' => null,
            'currency_code' => 'EUR',
        ],
        [
            'code' => 'complete_product_export',
            'name' => 'Esportazione completa prodotto',
            'description' => 'Fascicolo esportabile con documenti, coperture, eventi e pratiche collegati al prodotto.',
            'price_cents' => null,
            'currency_code' => 'EUR',
        ],
    ],
];
