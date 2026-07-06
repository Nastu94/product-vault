<?php

namespace App\Exceptions\ProductCases;

use RuntimeException;

final class ProductCaseNotReadyException extends RuntimeException
{
    /**
     * Snapshot completo restituito dal readiness resolver.
     *
     * @var array<string, mixed>
     */
    private readonly array $readiness;

    /**
     * Codici bloccanti normalizzati, univoci e ordinati.
     *
     * @var list<string>
     */
    private readonly array $blockingCodes;

    /**
     * @param  array<string, mixed>  $readiness
     */
    public function __construct(array $readiness)
    {
        $blockingCodes = [];

        foreach (
            $readiness['blocking_information'] ?? []
            as $item
        ) {
            if (! is_array($item)) {
                continue;
            }

            $code = $item['code'] ?? null;

            if (! is_string($code)) {
                continue;
            }

            $code = trim($code);

            if ($code !== '') {
                $blockingCodes[] = $code;
            }
        }

        $blockingCodes = array_values(
            array_unique($blockingCodes)
        );

        sort($blockingCodes);

        $this->readiness = $readiness;
        $this->blockingCodes = $blockingCodes;

        $message =
            'La pratica non è pronta per il contatto.';

        if ($blockingCodes !== []) {
            $message .=
                ' Informazioni bloccanti: '
                . implode(', ', $blockingCodes)
                . '.';
        }

        parent::__construct($message);
    }

    /**
     * @return list<string>
     */
    public function blockingCodes(): array
    {
        return $this->blockingCodes;
    }

    /**
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        return $this->readiness;
    }
}