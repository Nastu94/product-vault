<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feedback matcher scenarios
    |--------------------------------------------------------------------------
    |
    | Testano la knowledge locale/workspace.
    |
    */
    'feedback' => [
        [
            'name' => 'sony_same_model_split_tokens',
            'candidate_name' => 'Sony WH 1000 XM5 cuffie wireless nero',
            'ean_code' => null,
            'expect' => [
                'suggested_bias' => 'positive',
                'review_hint' => 'similar_description_previously_confirmed',
                'min_best_similarity' => 0.75,
                'contains_model_overlap' => ['wh1000xm5'],
            ],
        ],
        [
            'name' => 'sony_exact_compact_previously_confirmed',
            'candidate_name' => 'Sony WH1000XM5 wireless nero',
            'ean_code' => null,
            'expect' => [
                'suggested_bias' => 'previously_confirmed',
                'review_hint' => 'same_description_previously_confirmed',
                'min_product_identity_score' => 45,
                'min_registration_preference_score' => 45,
                'min_best_similarity' => 0.75,
                'contains_model_overlap' => ['wh1000xm5'],
            ],
        ],
        [
            'name' => 'sony_different_model_xm4',
            'candidate_name' => 'Sony WH-1000XM4 cuffie wireless nero',
            'ean_code' => null,
            'expect' => [
                'suggested_bias' => 'neutral',
                'max_best_similarity' => 0.74,
                'model_conflict' => true,
            ],
        ],
        [
            'name' => 'thinkpad_same_generation',
            'candidate_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
            'ean_code' => null,
            'expect' => [
                'suggested_bias' => 'previously_confirmed',
                'review_hint' => 'same_description_previously_confirmed',
                'min_product_identity_score' => 45,
                'min_registration_preference_score' => 45,
            ],
        ],
        [
            'name' => 'thinkpad_generation_conflict',
            'candidate_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 10',
            'ean_code' => null,
            'expect' => [
                'suggested_bias' => 'neutral',
                'max_best_similarity' => 0.74,
                'model_conflict' => true,
            ],
        ],
        [
            'name' => 'docking_known_ocr_name',
            'candidate_name' => 'Docking Station USB-C Duat HOMI 4K',
            'ean_code' => null,
            'expect' => [
                'suggested_bias' => 'previously_confirmed',
                'review_hint' => 'same_description_previously_confirmed',
                'min_product_identity_score' => 45,
            ],
        ],
        [
            'name' => 'ignored_adapter_should_not_boost_docking_by_4k',
            'candidate_name' => 'Docking Station USB-C HDMI 2 porte',
            'ean_code' => null,
            'expect' => [
                'suggested_bias' => 'neutral',
                'max_best_similarity' => 0.74,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Python similarity scenarios
    |--------------------------------------------------------------------------
    |
    | Testano fuzzy matching tecnico su global facts.
    |
    */
    'python' => [
        [
            'name' => 'thinkpad_same_generation_variant',
            'candidate_name' => 'Notebook Lenovo Thinkpad X1 Carbon Gen11',
            'suggested_category' => 'notebook',
            'suggested_line_type' => 'durable_product',
            'expect' => [
                'best_match' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                'min_similarity' => 90,
                'contains_signals' => [
                    'high_similarity_to_global_canonical_name',
                ],
                'not_contains_signals' => [
                    'candidate_name_similar_but_different_model',
                ],
                'not_contains_warnings' => [
                    'high_similarity_but_model_conflict',
                ],
            ],
        ],
        [
            'name' => 'thinkpad_generation_conflict',
            'candidate_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 10',
            'suggested_category' => 'notebook',
            'suggested_line_type' => 'durable_product',
            'expect' => [
                'best_match' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                'min_similarity' => 90,
                'contains_signals' => [
                    'candidate_name_similar_but_different_model',
                ],
                'contains_warnings' => [
                    'high_similarity_but_model_conflict',
                ],
                'not_contains_signals' => [
                    'candidate_name_probably_ocr_variant',
                ],
            ],
        ],
        [
            'name' => 'docking_ocr_variant',
            'candidate_name' => 'Dock Station USB C Dual HDMl 4K',
            'suggested_category' => 'docking_station',
            'suggested_line_type' => 'accessory',
            'expect' => [
                'best_match' => 'Docking Station USB-C Dual HDMI 4K',
                'min_similarity' => 90,
                'contains_signals' => [
                    'candidate_name_probably_ocr_variant',
                ],
                'not_contains_warnings' => [
                    'high_similarity_but_spec_difference',
                ],
            ],
        ],
        [
            'name' => 'docking_spec_difference',
            'candidate_name' => 'Docking Station USB-C HDMI 2 porte',
            'suggested_category' => 'docking_station',
            'suggested_line_type' => 'accessory',
            'expect' => [
                'best_match' => 'Docking Station USB-C Dual HDMI 4K',
                'min_similarity' => 80,
                'contains_signals' => [
                    'candidate_name_similar_but_spec_difference',
                ],
                'contains_warnings' => [
                    'high_similarity_but_spec_difference',
                ],
                'not_contains_signals' => [
                    'candidate_name_probably_ocr_variant',
                ],
            ],
        ],
        [
            'name' => 'docking_exact_canonical',
            'candidate_name' => 'Docking Station USB-C Dual HDMI 4K',
            'suggested_category' => 'docking_station',
            'suggested_line_type' => 'accessory',
            'expect' => [
                'best_match' => 'Docking Station USB-C Dual HDMI 4K',
                'min_similarity' => 99.5,
                'contains_signals' => [
                    'candidate_name_matches_global_canonical_name',
                ],
                'not_contains_warnings' => [
                    'high_similarity_but_spec_difference',
                    'high_similarity_but_model_conflict',
                ],
            ],
        ],
        [
            'name' => 'generic_accessory_should_not_use_weak_docking_match',
            'candidate_name' => 'Cavo USB-C nero 1 metro',
            'suggested_category' => null,
            'suggested_line_type' => 'accessory',
            'expect' => [
                'best_match' => null,
                'contains_signals' => [
                    'low_similarity_to_global_canonical_name',
                ],
                'contains_warnings' => [
                    'unusable_similarity_match',
                ],
            ],
        ],
        [
            'name' => 'powerbank_specs_should_not_match_anything',
            'candidate_name' => 'Powerbank 20000mAh PD 20W',
            'suggested_category' => 'powerbank',
            'suggested_line_type' => 'accessory',
            'expect' => [
                'best_match' => null,
                'contains_warnings' => [
                    'missing_global_facts',
                ],
            ],
        ],
    ],

    'pipeline' => [
        [
            'name' => 'similar_but_different_invoice_table',
            'document_type' => 'invoice',
            'raw_text_lines' => [
                'FATTURA',
                'Numero: PV-SYN-PIPE-001    Data: 03/06/2026',
                'Venditore',
                'TechHub Italia S.r.l.',
                '',
                'Righe documento',
                'Codice       Descrizione                                      Quantita  Prezzo unitario  Totale riga',
                'LEN-X1-G10   Notebook Lenovo ThinkPad X1 Carbon Gen 10        1         1.299,00         1.299,00',
                'DOCK-UCHD-2P Docking Station USB-C HDMI 2 porte               1         89,00            89,00',
                'SON-WHXM4    Sony WH-1000XM4 cuffie wireless nero             1         249,99           249,99',
                '',
                'Imponibile             1.342,61',
                'IVA 22%                295,38',
                'Totale documento EUR   1.637,99',
            ],
            'expect' => [
                'line_count' => 3,
                'candidate_count' => 3,
                'document_status' => 'needs_review',
                'lines' => [
                    [
                        'description' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 10',
                        'quantity' => '1.000',
                        'unit_price' => '1299.00',
                        'total_price' => '1299.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                    [
                        'description' => 'Docking Station USB-C HDMI 2 porte',
                        'quantity' => '1.000',
                        'unit_price' => '89.00',
                        'total_price' => '89.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                    [
                        'description' => 'Sony WH-1000XM4 cuffie wireless nero',
                        'quantity' => '1.000',
                        'unit_price' => '249.99',
                        'total_price' => '249.99',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                ],
                'candidates' => [
                    [
                        'name_contains' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 10',
                        'feedback_suggested_bias' => 'neutral',
                        'feedback_model_conflict' => true,
                        'python_best_match' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'python_contains_signals' => [
                            'candidate_name_similar_but_different_model',
                        ],
                        'python_contains_warnings' => [
                            'high_similarity_but_model_conflict',
                        ],
                        'python_not_contains_signals' => [
                            'candidate_name_probably_ocr_variant',
                        ],
                    ],
                    [
                        'name_contains' => 'Docking Station USB-C HDMI 2 porte',
                        'feedback_suggested_bias' => 'neutral',
                        'python_best_match' => 'Docking Station USB-C Dual HDMI 4K',
                        'python_contains_signals' => [
                            'candidate_name_similar_but_spec_difference',
                        ],
                        'python_contains_warnings' => [
                            'high_similarity_but_spec_difference',
                        ],
                        'python_not_contains_signals' => [
                            'candidate_name_probably_ocr_variant',
                        ],
                    ],
                    [
                        'name_contains' => 'Sony WH-1000XM4 cuffie wireless nero',
                        'feedback_suggested_bias' => 'neutral',
                        'feedback_model_conflict' => true,
                        'python_best_match' => null,
                        'python_contains_warnings' => [
                            'missing_global_facts',
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'known_products_ocr_variants_invoice_table',
            'document_type' => 'invoice',
            'raw_text_lines' => [
                'FATTURA',
                'Numero: PV-SYN-PIPE-002    Data: 04/06/2026',
                'Venditore',
                'TechHub Italia S.r.l.',
                '',
                'Righe documento',
                'Codice       Descrizione                                      Quantita  Prezzo unitario  Totale riga',
                'LEN-X1-G11   Notebook Lenovo Thinkpad X1 Carbon Gen11         1         1.499,00         1.499,00',
                'DOCK-UCDH-4K Dock Station USB C Dual HDMl 4K                  1         119,00           119,00',
                'SON-WHXM5    Sony WH 1000 XM5 cuffie wireless nero            1         299,99           299,99',
                '',
                'Imponibile             1.572,12',
                'IVA 22%                345,87',
                'Totale documento EUR   1.917,99',
            ],
            'expect' => [
                'line_count' => 3,
                'candidate_count' => 3,
                'document_status' => 'needs_review',
                'lines' => [
                    [
                        'description' => 'Notebook Lenovo Thinkpad X1 Carbon Gen11',
                        'quantity' => '1.000',
                        'unit_price' => '1499.00',
                        'total_price' => '1499.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                    [
                        'description' => 'Dock Station USB C Dual HDMl 4K',
                        'quantity' => '1.000',
                        'unit_price' => '119.00',
                        'total_price' => '119.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                    [
                        'description' => 'Sony WH 1000 XM5 cuffie wireless nero',
                        'quantity' => '1.000',
                        'unit_price' => '299.99',
                        'total_price' => '299.99',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                ],
                'candidates' => [
                    [
                        'name_contains' => 'X1 Carbon',
                        'python_best_match' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'python_contains_signals' => [
                            'high_similarity_to_global_canonical_name',
                        ],
                        'python_not_contains_signals' => [
                            'candidate_name_similar_but_different_model',
                        ],
                    ],
                    [
                        'name_contains' => 'Dock Station',
                        'python_best_match' => 'Docking Station USB-C Dual HDMI 4K',
                        'python_contains_signals' => [
                            'candidate_name_probably_ocr_variant',
                        ],
                        'python_not_contains_signals' => [
                            'candidate_name_similar_but_spec_difference',
                        ],
                    ],
                    [
                        'name_contains' => 'Sony WH 1000 XM5',
                        'feedback_suggested_bias' => 'positive',
                        'python_best_match' => null,
                        'python_contains_warnings' => [
                            'missing_global_facts',
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'ean_global_fact_invoice_table',
            'document_type' => 'invoice',
            'raw_text_lines' => [
                'FATTURA',
                'Numero: PV-SYN-PIPE-003    Data: 05/06/2026',
                'Venditore',
                'TechHub Italia S.r.l.',
                '',
                'Righe documento',
                'Codice       Descrizione                                                   Quantita  Prezzo unitario  Totale riga',
                'LEN-X1-G11   Notebook Lenovo ThinkPad X1 Carbon Gen 11 EAN 0196388123456   1         1.499,00         1.499,00',
                'DOCK-UCDH-4K Docking Station USB-C Duat HOMI 4K EAN 8055555012222         1         119,00           119,00',
                '',
                'Imponibile             1.326,23',
                'IVA 22%                291,77',
                'Totale documento EUR   1.618,00',
            ],
            'expect' => [
                'line_count' => 2,
                'candidate_count' => 2,
                'document_status' => 'needs_review',
                'lines' => [
                    [
                        'description' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'quantity' => '1.000',
                        'unit_price' => '1499.00',
                        'total_price' => '1499.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                    [
                        'description' => 'Docking Station USB-C Duat HOMI 4K',
                        'quantity' => '1.000',
                        'unit_price' => '119.00',
                        'total_price' => '119.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                ],
                'candidates' => [
                    [
                        'name_contains' => 'ThinkPad X1 Carbon Gen 11',
                        'ean_code' => '0196388123456',
                        'global_fact_matched' => true,
                        'global_fact_canonical_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'global_fact_contains_signals' => [
                            'global_ean_fact_found',
                            'global_confirmations_present',
                        ],
                        'python_best_match' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'python_contains_signals' => [
                            'high_similarity_to_global_canonical_name',
                        ],
                    ],
                    [
                        'name_contains' => 'Docking Station USB-C Duat HOMI 4K',
                        'ean_code' => '8055555012222',
                        'global_fact_matched' => true,
                        'global_fact_canonical_name' => 'Docking Station USB-C Dual HDMI 4K',
                        'global_fact_contains_signals' => [
                            'global_ean_fact_found',
                            'global_confirmations_present',
                            'global_ignored_observations_present',
                        ],
                        'python_best_match' => 'Docking Station USB-C Dual HDMI 4K',
                        'python_contains_signals' => [
                            'high_similarity_to_global_canonical_name',
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'ean_column_global_fact_invoice_table',
            'document_type' => 'invoice',
            'raw_text_lines' => [
                'FATTURA',
                'Numero: PV-SYN-PIPE-004    Data: 06/06/2026',
                'Venditore',
                'TechHub Italia S.r.l.',
                '',
                'Righe documento',
                'Codice       Descrizione                                      EAN             Quantita  Prezzo unitario  Totale riga',
                'LEN-X1-G11   Notebook Lenovo ThinkPad X1 Carbon Gen 11        0196388123456   1         1.499,00         1.499,00',
                'DOCK-UCDH-4K Docking Station USB-C Duat HOMI 4K               8055555012222   1         119,00           119,00',
                '',
                'Imponibile             1.326,23',
                'IVA 22%                291,77',
                'Totale documento EUR   1.618,00',
            ],
            'expect' => [
                'line_count' => 2,
                'candidate_count' => 2,
                'document_status' => 'needs_review',
                'lines' => [
                    [
                        'description' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'quantity' => '1.000',
                        'unit_price' => '1499.00',
                        'total_price' => '1499.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                    [
                        'description' => 'Docking Station USB-C Duat HOMI 4K',
                        'quantity' => '1.000',
                        'unit_price' => '119.00',
                        'total_price' => '119.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                ],
                'candidates' => [
                    [
                        'name_contains' => 'ThinkPad X1 Carbon Gen 11',
                        'ean_code' => '0196388123456',
                        'global_fact_matched' => true,
                        'global_fact_canonical_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'global_fact_contains_signals' => [
                            'global_ean_fact_found',
                            'global_confirmations_present',
                        ],
                        'python_best_match' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'python_contains_signals' => [
                            'high_similarity_to_global_canonical_name',
                        ],
                    ],
                    [
                        'name_contains' => 'Docking Station USB-C Duat HOMI 4K',
                        'ean_code' => '8055555012222',
                        'global_fact_matched' => true,
                        'global_fact_canonical_name' => 'Docking Station USB-C Dual HDMI 4K',
                        'global_fact_contains_signals' => [
                            'global_ean_fact_found',
                            'global_confirmations_present',
                            'global_ignored_observations_present',
                        ],
                        'python_best_match' => 'Docking Station USB-C Dual HDMI 4K',
                        'python_contains_signals' => [
                            'high_similarity_to_global_canonical_name',
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'serial_column_invoice_table',
            'document_type' => 'invoice',
            'raw_text_lines' => [
                'FATTURA',
                'Numero: PV-SYN-PIPE-005    Data: 07/06/2026',
                'Venditore',
                'TechHub Italia S.r.l.',
                '',
                'Righe documento',
                'Codice       Descrizione                                      Seriale       Quantita  Prezzo unitario  Totale riga',
                'LEN-X1-G11   Notebook Lenovo ThinkPad X1 Carbon Gen 11        PF4TEST0091   1         1.499,00         1.499,00',
                'DOCK-UCDH-4K Docking Station USB-C Dual HDMI 4K               DS4KTEST77    1         119,00           119,00',
                '',
                'Imponibile             1.326,23',
                'IVA 22%                291,77',
                'Totale documento EUR   1.618,00',
            ],
            'expect' => [
                'line_count' => 2,
                'candidate_count' => 2,
                'document_status' => 'needs_review',
                'lines' => [
                    [
                        'description' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'quantity' => '1.000',
                        'unit_price' => '1499.00',
                        'total_price' => '1499.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                    [
                        'description' => 'Docking Station USB-C Dual HDMI 4K',
                        'quantity' => '1.000',
                        'unit_price' => '119.00',
                        'total_price' => '119.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                ],
                'candidates' => [
                    [
                        'name_contains' => 'ThinkPad X1 Carbon Gen 11',
                        'serial_number' => 'PF4TEST0091',
                        'python_best_match' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'python_contains_signals' => [
                            'high_similarity_to_global_canonical_name',
                        ],
                    ],
                    [
                        'name_contains' => 'Docking Station USB-C Dual HDMI 4K',
                        'serial_number' => 'DS4KTEST77',
                        'python_best_match' => 'Docking Station USB-C Dual HDMI 4K',
                        'python_contains_signals' => [
                            'high_similarity_to_global_canonical_name',
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'amount_mismatch_invoice_table',
            'document_type' => 'invoice',
            'raw_text_lines' => [
                'FATTURA',
                'Numero: PV-SYN-PIPE-006    Data: 08/06/2026',
                'Venditore',
                'TechHub Italia S.r.l.',
                '',
                'Righe documento',
                'Codice       Descrizione                                      Quantita  Prezzo unitario  Totale riga',
                'LEN-X1-G11   Notebook Lenovo ThinkPad X1 Carbon Gen 11        2         1.499,00         1.499,00',
                'DOCK-UCDH-4K Docking Station USB-C Dual HDMI 4K               3         119,00           119,00',
                '',
                'Imponibile             1.326,23',
                'IVA 22%                291,77',
                'Totale documento EUR   1.618,00',
            ],
            'expect' => [
                'line_count' => 0,
                'candidate_count' => 0,
                'document_status' => 'parsed',
                'lines' => [],
                'candidates' => [],
            ],
        ],
        [
            'name' => 'amount_coherent_quantity_two_invoice_table',
            'document_type' => 'invoice',
            'raw_text_lines' => [
                'FATTURA',
                'Numero: PV-SYN-PIPE-007    Data: 09/06/2026',
                'Venditore',
                'TechHub Italia S.r.l.',
                '',
                'Righe documento',
                'Codice       Descrizione                                      Quantita  Prezzo unitario  Totale riga',
                'LEN-X1-G11   Notebook Lenovo ThinkPad X1 Carbon Gen 11        2         749,50           1.499,00',
                'DOCK-UCDH-4K Docking Station USB-C Dual HDMI 4K               2         59,50            119,00',
                '',
                'Imponibile             1.326,23',
                'IVA 22%                291,77',
                'Totale documento EUR   1.618,00',
            ],
            'expect' => [
                'line_count' => 2,
                'candidate_count' => 2,
                'document_status' => 'needs_review',
                'lines' => [
                    [
                        'description' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'quantity' => '2.000',
                        'unit_price' => '749.50',
                        'total_price' => '1499.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                    [
                        'description' => 'Docking Station USB-C Dual HDMI 4K',
                        'quantity' => '2.000',
                        'unit_price' => '59.50',
                        'total_price' => '119.00',
                        'mode' => 'text_invoice_table_header_roles',
                    ],
                ],
                'candidates' => [
                    [
                        'name_contains' => 'ThinkPad X1 Carbon Gen 11',
                        'python_best_match' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                        'python_contains_signals' => [
                            'high_similarity_to_global_canonical_name',
                        ],
                    ],
                    [
                        'name_contains' => 'Docking Station USB-C Dual HDMI 4K',
                        'python_best_match' => 'Docking Station USB-C Dual HDMI 4K',
                        'python_contains_signals' => [
                            'high_similarity_to_global_canonical_name',
                        ],
                    ],
                ],
            ],
        ],
    ],
];