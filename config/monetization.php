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
];
