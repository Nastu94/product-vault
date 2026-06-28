<?php

namespace App\Services\Warranties;

use App\Models\Product;

final class ManualWarrantyCoverageContextBuilder
{
    public const VERSION = 'v1';

    /**
     * Costruisce il contesto versionato per una copertura salvata
     * manualmente dall'utente.
     *
     * Il contesto precedente viene preservato quando utilizzabile,
     * mentre stato, provenienza della data e conferma vengono
     * aggiornati per descrivere l'azione appena eseguita.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function build(
        Product $product,
        array $metadata,
        int $userId,
        string $confirmedAt,
        array $input = []
    ): array {
        $existingContext = data_get(
            $metadata,
            'coverage_context',
            []
        );

        $existingContext = is_array($existingContext)
            ? $existingContext
            : [];

        $countryCode = $this->normalizeCountryCode(
            data_get(
                $existingContext,
                'jurisdiction.country_code',
                data_get(
                    $metadata,
                    'country_code'
                )
            )
        );

        $defaults = [
            'version' => self::VERSION,
            'state' => 'user_confirmed',

            'purchase' => [
                'use' => 'unknown',
                'seller_type' => 'unknown',
            ],

            'product' => [
                'condition' => 'unknown',
            ],

            'jurisdiction' => [
                'country_code' => $countryCode,
            ],

            'dates' => [
                'purchased_at' =>
                    $product->purchase_date?->toDateString(),
                'delivered_at' => null,
                'starts_at_source' => 'manual_user_input',
            ],

            'declared_coverage' => [
                'present' => null,
            ],

            'confirmation' => [
                'applied' => true,
                'confirmed_at' => $confirmedAt,
                'confirmed_by_user_id' => $userId,
            ],
        ];

        $context = array_replace_recursive(
            $defaults,
            $existingContext,
        );

        /*
         * Metadata legacy o malformati non devono rompere il
         * contratto delle sezioni principali.
         */
        foreach (
            [
                'purchase',
                'product',
                'jurisdiction',
                'dates',
                'declared_coverage',
                'confirmation',
            ] as $section
        ) {
            if (! is_array($context[$section] ?? null)) {
                $context[$section] = $defaults[$section];
            }
        }

        /*
        * I valori contestuali possono arrivare dal form oppure essere già
        * presenti nei metadata. Ogni valore viene normalizzato prima di
        * essere inserito nel contratto persistito.
        */
        $context['purchase']['use'] =
            $this->normalizeEnumValue(
                array_key_exists('purchase_use', $input)
                    ? $input['purchase_use']
                    : ($context['purchase']['use'] ?? null),
                [
                    'personal',
                    'business',
                    'unknown',
                ],
            );

        $context['purchase']['seller_type'] =
            $this->normalizeEnumValue(
                array_key_exists('seller_type', $input)
                    ? $input['seller_type']
                    : ($context['purchase']['seller_type'] ?? null),
                [
                    'professional',
                    'private',
                    'unknown',
                ],
            );

        $context['product']['condition'] =
            $this->normalizeEnumValue(
                array_key_exists('product_condition', $input)
                    ? $input['product_condition']
                    : ($context['product']['condition'] ?? null),
                [
                    'new',
                    'used',
                    'refurbished',
                    'unknown',
                ],
            );

        if (array_key_exists('country_code', $input)) {
            $countryCode = $this->normalizeCountryCode(
                $input['country_code']
            );
        }

        $context['jurisdiction']['country_code'] =
            $countryCode;

        if (array_key_exists('delivered_at', $input)) {
            $context['dates']['delivered_at'] =
                $this->normalizeDate(
                    $input['delivered_at']
                );
        } else {
            $context['dates']['delivered_at'] =
                $this->normalizeDate(
                    $context['dates']['delivered_at'] ?? null
                );
        }

        if (array_key_exists('declared_coverage', $input)) {
            $context['declared_coverage']['present'] =
                $this->normalizeNullableBoolean(
                    $input['declared_coverage']
                );
        } else {
            $context['declared_coverage']['present'] =
                $this->normalizeNullableBoolean(
                    $context['declared_coverage']['present']
                        ?? null
                );
        }

        /*
         * I valori seguenti descrivono l'azione manuale appena
         * eseguita e devono prevalere sul contesto precedente.
         */
        $context['version'] = self::VERSION;
        $context['state'] = 'user_confirmed';

        $context['dates']['starts_at_source'] =
            'manual_user_input';

        if (
            ! is_string(
                $context['dates']['purchased_at'] ?? null
            )
            || trim(
                $context['dates']['purchased_at']
            ) === ''
        ) {
            $context['dates']['purchased_at'] =
                $product->purchase_date?->toDateString();
        }

        $context['confirmation'] = [
            'applied' => true,
            'confirmed_at' => $confirmedAt,
            'confirmed_by_user_id' => $userId,
        ];

        return $context;
    }

    /**
     * @param list<string> $allowedValues
     */
    private function normalizeEnumValue(
        mixed $value,
        array $allowedValues
    ): string {
        if (! is_string($value)) {
            return 'unknown';
        }

        $value = strtolower(trim($value));

        return in_array(
            $value,
            $allowedValues,
            true
        )
            ? $value
            : 'unknown';
    }

    private function normalizeDate(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (
            ! preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $value
            )
        ) {
            return null;
        }

        [$year, $month, $day] = array_map(
            'intval',
            explode('-', $value)
        );

        return checkdate($month, $day, $year)
            ? $value
            : null;
    }

    private function normalizeNullableBoolean(
        mixed $value
    ): ?bool {
        if (is_bool($value)) {
            return $value;
        }

        if (
            $value === 1
            || $value === '1'
            || $value === 'true'
        ) {
            return true;
        }

        if (
            $value === 0
            || $value === '0'
            || $value === 'false'
        ) {
            return false;
        }

        return null;
    }

    private function normalizeCountryCode(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return $value !== ''
            ? $value
            : null;
    }
}