<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'tesseract' => [
        'binary' => env('TESSERACT_BINARY', 'tesseract'),
        'lang' => env('TESSERACT_LANG', 'ita+eng'),
        'psm' => env('TESSERACT_PSM', 6),
        'timeout' => env('TESSERACT_TIMEOUT', 60),
    ],

    'ocr' => [
        'primary_engine' => env('OCR_PRIMARY_ENGINE', 'paddleocr'),
    ],

    'paddleocr' => [
        'python' => env('PADDLE_OCR_PYTHON', base_path('tools/ocr/.venv/Scripts/python.exe')),
        'script' => env('PADDLE_OCR_SCRIPT', base_path('tools/ocr/paddle_ocr_extract.py')),
        'lang' => env('PADDLE_OCR_LANG', 'it'),
        'timeout' => env('PADDLE_OCR_TIMEOUT', 180),
        'min_confidence' => env('PADDLE_OCR_MIN_CONFIDENCE', 65),
    ],

    'poppler' => [
        'pdftoppm' => env('POPPLER_PDFTOPPM_BINARY', 'pdftoppm'),
        'pdf_ocr_dpi' => env('PDF_OCR_DPI', 220),
        'pdf_ocr_max_pages' => env('PDF_OCR_MAX_PAGES', 3),
        'pdf_ocr_timeout' => env('PDF_OCR_TIMEOUT', 180),
    ],
];
