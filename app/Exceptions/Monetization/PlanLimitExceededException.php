<?php

namespace App\Exceptions\Monetization;

use RuntimeException;

final class PlanLimitExceededException extends RuntimeException
{
    /** @param array<string, mixed> $decision */
    public function __construct(
        public readonly array $decision
    ) {
        parent::__construct(
            (string) ($decision['message']
                ?? 'Il limite del piano è stato raggiunto.')
        );
    }
}
