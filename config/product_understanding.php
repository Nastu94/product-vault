<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Product Understanding
    |--------------------------------------------------------------------------
    |
    | Configurazione locale del motore gratuito di riconoscimento prodotto.
    |
    | Questa NON deve diventare una lista infinita di parole. È una tassonomia
    | iniziale, versionata, che più avanti potrà essere spostata in DB e arricchita
    | tramite feedback manuale confirmed/ignored.
    |
    */

    'version' => 'product_line_analyzer_v1',

    'thresholds' => [
        'candidate' => 65,
        'conflict_registerable' => 55,
        'conflict_non_product' => 35,
    ],

    'scores' => [
        'description_present' => 8,
        'missing_description' => -15,
        'positive_price' => 10,
        'missing_positive_price' => -10,
        'ean_detected' => 35,
        'serial_detected' => 25,
        'model_or_sku_detected' => 18,
        'brand_detected' => 8,
        'high_line_confidence' => 5,
        'structured_purchase_document' => 5,

        /*
        |--------------------------------------------------------------------------
        | Pesi categoria
        |--------------------------------------------------------------------------
        |
        | direct = segnale trovato nella descrizione principale.
        | context = segnale trovato solo nel raw_text/supporting lines.
        |
        | Questo evita casi come:
        | "Docking Station USB-C Dual HDMI 4K ... compatibile notebook"
        | classificata come notebook solo perché "notebook" è nel contesto.
        |
        */
        'durable_category_direct' => 30,
        'durable_category_context' => 16,
        'accessory_category_direct' => 22,
        'accessory_category_context' => 10,
        'consumable_category_direct' => -35,
        'consumable_category_context' => -20,
        'service_category_direct' => -45,
        'service_category_context' => -30,
        'accounting_or_payment_noise' => -50,
        'hard_exclusion' => -100,
    ],

    'hard_exclusions' => [
        'prefixes' => [
            'ship' => 'shipping',
            'trasp' => 'shipping',
            'serv' => 'service',
            'sconto' => 'discount',
            'discount' => 'discount',
            'gar-ext' => 'extended_warranty',
        ],

        'signals' => [
            'spedizione' => 'shipping',
            'trasporto' => 'shipping',
            'consegna' => 'shipping',
            'coupon' => 'discount',
            'sconti totali' => 'discount',
            'sconto' => 'discount',
            'pagamento' => 'payment',
            'bancomat' => 'payment',
            'pos' => 'payment',
            'resto' => 'payment',
            'garanzia commerciale' => 'extended_warranty',
            'garanzia estesa' => 'extended_warranty',
            'estensione garanzia' => 'extended_warranty',
            'extended warranty' => 'extended_warranty',
        ],
    ],

    'brands' => [
        'apple',
        'samsung',
        'lenovo',
        'hp',
        'dell',
        'asus',
        'acer',
        'sony',
        'lg',
        'philips',
        'xiaomi',
        'huawei',
        'canon',
        'epson',
        'logitech',
        'dyson',
        'irobot',
        'bose',
        'jbl',
        'nintendo',
        'microsoft',
    ],

    'categories' => [
        'durable_product' => [
            'smartphone' => [
                'smartphone',
                'telefono',
                'iphone',
                'galaxy',
                'pixel',
            ],
            'tablet' => [
                'tablet',
                'ipad',
            ],
            'notebook' => [
                'notebook',
                'laptop',
                'thinkpad',
                'macbook',
                'pc portatile',
                'computer',
            ],
            'monitor' => [
                'monitor',
                'display',
            ],
            'tv' => [
                'televisore',
                'smart tv',
                'tv',
            ],
            'printer' => [
                'stampante',
                'printer',
            ],
            'network_device' => [
                'router',
                'modem',
                'wifi',
                'wi fi',
                'access point',
            ],
            'vacuum_cleaner' => [
                'aspirapolvere',
                'robot aspirapolvere',
                'lavapavimenti',
                'robot lavapavimenti',
            ],
            'home_appliance' => [
                'frigorifero',
                'lavatrice',
                'lavastoviglie',
                'asciugatrice',
                'forno',
                'microonde',
                'friggitrice',
                'air fryer',
            ],
            'console' => [
                'console',
                'playstation',
                'xbox',
                'nintendo',
                'switch',
            ],
            'audio_device' => [
                'cuffie',
                'auricolari',
                'speaker',
                'soundbar',
            ],
            'smart_light' => [
                'lampada led smart',
                'led smart',
            ],
        ],

        'accessory' => [
            'docking_station' => [
                'docking',
                'dock',
                'docking station',
            ],
            'charger' => [
                'caricatore',
                'alimentatore',
                'charger',
            ],
            'cable' => [
                'cavo usb',
                'usb-c',
                'usb c',
                'hdmi',
                'lightning',
            ],
            'phone_case' => [
                'cover',
                'custodia',
                'case smartphone',
            ],
            'screen_protector' => [
                'protezione schermo',
                'proteggi schermo',
                'pellicola',
                'vetro temperato',
                'tempered glass',
                'screen protector',
            ],
            'adapter' => [
                'adattatore',
                'adapter',
            ],
            'powerbank' => [
                'powerbank',
                'power bank',
            ],
        ],

        'consumable' => [
            'food' => [
                'menu',
                'pranzo',
                'cena',
                'pizza',
                'pasta',
                'ravioli',
                'gnocchi',
                'vino',
                'birra',
                'acqua',
                'bevanda',
                'dolce',
                'banana',
                'banane',
                'latte',
                'pane',
                'biscotti',
                'pomodoro',
            ],
            'cleaning' => [
                'detergente',
                'detersivo',
                'pulizia',
                'sanificante',
                'panno',
                'microfibra',
                'spugne',
                'multiuso',
            ],
            'personal_care' => [
                'shampoo',
                'dentifricio',
                'sapone',
            ],
            'paper_goods' => [
                'carta igienica',
                'rotolo cucina',
                'tovaglioli',
            ],
            'bags_or_refills' => [
                'sacchetti',
                'ricambio sacchetti',
                'filtro ricambio',
            ],
            'batteries_consumable' => [
                'pile aa',
                'pile aaa',
                'pile alcaline',
                'batterie alcaline',
            ],
        ],

        'service' => [
            'configuration_service' => [
                'configurazione',
                'migrazione dati',
                'installazione',
                'setup',
            ],
            'repair_service' => [
                'riparazione',
                'manodopera',
                'assistenza',
                'intervento tecnico',
            ],
            'delivery_service' => [
                'spedizione',
                'trasporto',
                'consegna',
            ],
        ],
    ],

    'accounting_noise' => [
        'subtotale',
        'totale',
        'pagamento',
        'bancomat',
        'contanti',
        'resto',
        'iva',
        'imponibile',
        'scontrino',
        'documento gestionale',
        'documento di test',
        'grazie per aver acquistato',
    ],
];