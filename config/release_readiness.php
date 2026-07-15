<?php

return [
    'fixture_workspace_patterns' => [
        '/\btest\b/i',
        '/\btesting\b/i',
        '/\bfixture\b/i',
        '/\bdemo\b/i',
        '/product understanding/i',
        '/warranty lifecycle/i',
    ],

    'allow_fixture_workspaces' => env(
        'RELEASE_ALLOW_FIXTURE_WORKSPACES',
        false
    ),

    'required_public_routes' => [
        'welcome',
        'legal.privacy',
        'legal.terms',
        'legal.document-processing',
    ],

    'required_authenticated_routes' => [
        'dashboard',
        'documents.index',
        'documents.upload',
        'documents.preview',
        'documents.download',
        'products.index',
        'product-cases.index',
        'warranties.index',
        'reviews.index',
        'account.plan',
        'account.getting-started',
    ],

    'required_tools' => [
        'tesseract' => [
            'label' => 'Tesseract OCR',
            'config_key' => 'services.tesseract.binary',
            'required' => false,
        ],
        'pdftoppm' => [
            'label' => 'Poppler pdftoppm',
            'config_key' => 'services.poppler.pdftoppm',
            'required' => true,
        ],
        'paddle_python' => [
            'label' => 'PaddleOCR Python',
            'config_key' => 'services.paddleocr.python',
            'required' => true,
        ],
        'paddle_script' => [
            'label' => 'PaddleOCR script',
            'config_key' => 'services.paddleocr.script',
            'required' => true,
            'file' => true,
        ],
    ],

    'smoke_commands' => [
        'product-vault:test-monetization-foundation',
        'product-vault:test-monetization-usage-guard',
        'product-vault:test-monetization-domain-metering',
        'product-vault:test-product-case-workflow',
        'product-vault:test-warranty-lifecycle',
        'product-vault:test-dashboard-action-hierarchy',
        'product-vault:test-welcome-monetization',
        'product-vault:test-monetization-overview-ui',
        'product-vault:test-release-readiness',
        'product-vault:test-release-legal-ui',
        'product-vault:test-release-failure-safety',
    ],

    'legal' => [
        'effective_date' => env(
            'LEGAL_EFFECTIVE_DATE',
            '2026-07-15'
        ),
        'support_email' => env(
            'LEGAL_SUPPORT_EMAIL',
            env('MAIL_FROM_ADDRESS', 'support@example.com')
        ),
        'controller_name' => env(
            'LEGAL_CONTROLLER_NAME',
            config('app.name', 'Product Vault')
        ),
    ],

    'production' => [
        'disallowed_queue_drivers' => [
            'sync',
            'null',
        ],
        'disallowed_mailers' => [
            'log',
            'array',
        ],
        'require_https' => env(
            'RELEASE_REQUIRE_HTTPS',
            true
        ),
        'max_failed_jobs_warning' => (int) env(
            'RELEASE_MAX_FAILED_JOBS_WARNING',
            0
        ),
    ],
];
