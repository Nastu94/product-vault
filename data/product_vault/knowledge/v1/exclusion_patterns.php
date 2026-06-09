<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Exclusion patterns
    |--------------------------------------------------------------------------
    |
    | Pattern negativi per ridurre candidati falsi.
    | Questi dati non vengono ancora applicati automaticamente.
    |
    */

    'candidate_suppression_patterns' => [
        [
            'pattern' => 'totale',
            'document_line_type' => 'total',
            'reason' => 'total_line',
            'weight' => -100,
        ],
        [
            'pattern' => 'subtotale',
            'document_line_type' => 'total',
            'reason' => 'subtotal_line',
            'weight' => -90,
        ],
        [
            'pattern' => 'iva',
            'document_line_type' => 'tax',
            'reason' => 'tax_line',
            'weight' => -90,
        ],
        [
            'pattern' => 'imponibile',
            'document_line_type' => 'tax',
            'reason' => 'taxable_amount_line',
            'weight' => -80,
        ],
        [
            'pattern' => 'pagamento',
            'document_line_type' => 'payment',
            'reason' => 'payment_line',
            'weight' => -90,
        ],
        [
            'pattern' => 'bancomat',
            'document_line_type' => 'payment',
            'reason' => 'payment_line',
            'weight' => -90,
        ],
        [
            'pattern' => 'carta',
            'document_line_type' => 'payment',
            'reason' => 'payment_line',
            'weight' => -70,
        ],
        [
            'pattern' => 'contanti',
            'document_line_type' => 'payment',
            'reason' => 'payment_line',
            'weight' => -90,
        ],
        [
            'pattern' => 'resto',
            'document_line_type' => 'payment',
            'reason' => 'change_line',
            'weight' => -90,
        ],
        [
            'pattern' => 'sconto',
            'document_line_type' => 'discount',
            'reason' => 'discount_line',
            'weight' => -90,
        ],
        [
            'pattern' => 'coupon',
            'document_line_type' => 'discount',
            'reason' => 'discount_line',
            'weight' => -80,
        ],
        [
            'pattern' => 'voucher',
            'document_line_type' => 'discount',
            'reason' => 'discount_line',
            'weight' => -80,
        ],
        [
            'pattern' => 'cashback',
            'document_line_type' => 'discount',
            'reason' => 'discount_line',
            'weight' => -80,
        ],
        [
            'pattern' => 'righe documento',
            'document_line_type' => 'merchant_info',
            'reason' => 'technical_section_heading',
            'weight' => -100,
        ],
        [
            'pattern' => 'descrizione',
            'document_line_type' => 'merchant_info',
            'reason' => 'table_header',
            'weight' => -70,
        ],
        [
            'pattern' => 'quantità',
            'document_line_type' => 'merchant_info',
            'reason' => 'table_header',
            'weight' => -70,
        ],
        [
            'pattern' => 'prezzo unitario',
            'document_line_type' => 'merchant_info',
            'reason' => 'table_header',
            'weight' => -70,
        ],
        [
            'pattern' => 'codice articolo',
            'document_line_type' => 'merchant_info',
            'reason' => 'table_header',
            'weight' => -60,
        ],
    ],

    'weak_similarity_stopwords' => [
        'pro',
        'max',
        'plus',
        'mini',
        'smart',
        'new',
        'black',
        'white',
        'blue',
        'red',
        'green',
        'nero',
        'bianco',
        'blu',
        'rosso',
        'verde',
        'wireless',
        'usb',
        'type',
        'c',
    ],
];