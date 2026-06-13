<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Line patterns
    |--------------------------------------------------------------------------
    |
    | Pattern lessicali iniziali per aiutare Product Understanding.
    | Questi dati non vengono ancora applicati automaticamente.
    |
    | document_line_type deve usare i codici realmente presenti in DB:
    | discount, merchant_info, payment, product, tax, total, unknown.
    |
    */

    'product_keywords' => [
        [
            'pattern' => 'notebook',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'durable_product',
            'weight' => 30,
        ],
        [
            'pattern' => 'laptop',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'durable_product',
            'weight' => 30,
        ],
        [
            'pattern' => 'monitor',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'durable_product',
            'weight' => 25,
        ],
        [
            'pattern' => 'nas',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'durable_product',
            'weight' => 25,
        ],
        [
            'pattern' => 'network attached storage',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'durable_product',
            'weight' => 25,
        ],
        [
            'pattern' => 'hard disk',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'accessory',
            'weight' => 18,
        ],
        [
            'pattern' => 'ssd',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'accessory',
            'weight' => 18,
        ],
        [
            'pattern' => 'smartphone',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'smartphones',
            'product_kind' => 'durable_product',
            'weight' => 30,
        ],
        [
            'pattern' => 'tablet',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'electronics',
            'product_kind' => 'durable_product',
            'weight' => 25,
        ],
        [
            'pattern' => 'cuffie',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'tv-audio',
            'product_kind' => 'accessory',
            'weight' => 20,
        ],
        [
            'pattern' => 'headphones',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'tv-audio',
            'product_kind' => 'accessory',
            'weight' => 20,
        ],
        [
            'pattern' => 'tastiera',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'accessory',
            'weight' => 20,
        ],
        [
            'pattern' => 'keyboard',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'accessory',
            'weight' => 20,
        ],
        [
            'pattern' => 'mouse',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'accessory',
            'weight' => 20,
        ],
        [
            'pattern' => 'docking station',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'accessory',
            'weight' => 25,
        ],
        [
            'pattern' => 'hub usb',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'accessory',
            'weight' => 20,
        ],
        [
            'pattern' => 'stampante',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'durable_product',
            'weight' => 25,
        ],
        [
            'pattern' => 'printer',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'computers',
            'product_kind' => 'durable_product',
            'weight' => 25,
        ],
        [
            'pattern' => 'fotocamera',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'electronics',
            'product_kind' => 'durable_product',
            'weight' => 25,
        ],
        [
            'pattern' => 'mirrorless',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'electronics',
            'product_kind' => 'durable_product',
            'weight' => 25,
        ],
        [
            'pattern' => 'obiettivo',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'electronics',
            'product_kind' => 'accessory',
            'weight' => 20,
        ],
        [
            'pattern' => 'gimbal',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'electronics',
            'product_kind' => 'accessory',
            'weight' => 20,
        ],
        [
            'pattern' => 'stabilizzatore',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'electronics',
            'product_kind' => 'accessory',
            'weight' => 20,
        ],
        [
            'pattern' => 'lavatrice',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'large-appliances',
            'product_kind' => 'durable_product',
            'weight' => 30,
        ],
        [
            'pattern' => 'aspirapolvere',
            'document_line_type' => 'product',
            'suggested_category_slug' => 'small-appliances',
            'product_kind' => 'durable_product',
            'weight' => 25,
        ],
    ],

    'service_keywords' => [
        [
            'pattern' => 'spedizione',
            'document_line_type' => 'unknown',
            'semantic_group' => 'service',
            'candidate_bias' => 'negative',
            'weight' => -30,
        ],
        [
            'pattern' => 'consegna',
            'document_line_type' => 'unknown',
            'semantic_group' => 'service',
            'candidate_bias' => 'negative',
            'weight' => -25,
        ],
        [
            'pattern' => 'installazione',
            'document_line_type' => 'unknown',
            'semantic_group' => 'service',
            'candidate_bias' => 'negative',
            'weight' => -25,
        ],
        [
            'pattern' => 'configurazione',
            'document_line_type' => 'unknown',
            'semantic_group' => 'service',
            'candidate_bias' => 'negative',
            'weight' => -20,
        ],
    ],

    'warranty_keywords' => [
        [
            'pattern' => 'garanzia estesa',
            'document_line_type' => 'unknown',
            'semantic_group' => 'warranty',
            'candidate_bias' => 'negative_for_product_identity',
            'weight' => -20,
        ],
        [
            'pattern' => 'estensione garanzia',
            'document_line_type' => 'unknown',
            'semantic_group' => 'warranty',
            'candidate_bias' => 'negative_for_product_identity',
            'weight' => -20,
        ],
        [
            'pattern' => 'applecare',
            'document_line_type' => 'unknown',
            'semantic_group' => 'warranty',
            'candidate_bias' => 'negative_for_product_identity',
            'weight' => -15,
        ],
        [
            'pattern' => 'protezione prodotto',
            'document_line_type' => 'unknown',
            'semantic_group' => 'warranty',
            'candidate_bias' => 'negative_for_product_identity',
            'weight' => -15,
        ],
    ],
];