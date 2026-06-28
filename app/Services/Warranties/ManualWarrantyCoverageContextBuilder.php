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
     *
     * @return array<string, mixed>
     */
    public function build(
        Product $product,
        array $metadata,
        int $userId,
        string $confirmedAt
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
         * I valori seguenti descrivono l'azione manuale appena
         * eseguita e devono prevalere sul contesto precedente.
         */
        $context['version'] = self::VERSION;
        $context['state'] = 'user_confirmed';

        $context['jurisdiction']['country_code'] =
            $countryCode;

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