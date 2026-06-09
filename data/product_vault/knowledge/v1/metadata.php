<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Product Vault initial knowledge pack metadata
    |--------------------------------------------------------------------------
    |
    | Questo file descrive il primo knowledge pack versionato.
    | I dati qui definiti non devono essere confusi con fixture di test o con
    | global facts generati dagli utenti durante la revisione dei candidati.
    |
    */

    'version' => 'initial_knowledge_pack_v1',

    'name' => 'Initial Product Vault Knowledge Pack v1',

    'description' => 'Base iniziale controllata per brand, alias e pattern lessicali usati dal Product Understanding.',

    'intended_environment' => [
        'local',
        'development',
        'demo',
        'production_seed',
    ],

    'imports' => [
        'brands' => true,
        'brand_aliases' => false,
        'line_patterns' => false,
        'exclusion_patterns' => false,
        'global_facts' => false,
    ],

    'rules' => [
        'do_not_create_global_facts' => true,
        'do_not_touch_user_feedback' => true,
        'do_not_touch_user_products' => true,
        'do_not_import_unverified_eans' => true,
        'keep_fixture_data_separated' => true,
    ],

    'notes' => [
        'La prima implementazione importerà solo brand globali nella tabella brands.',
        'Alias, pattern ed esclusioni restano file dati versionati finché non vengono integrati in service dedicati.',
        'Le categorie non sono incluse perché CategorySeeder popola già categorie globali idempotenti.',
    ],
];