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
];