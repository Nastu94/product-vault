<?php

namespace App\Exceptions\ProductCases;

use RuntimeException;

final class ProductCaseRequestDraftProtectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'La bozza è stata modificata manualmente e non può essere sovrascritta automaticamente.'
        );
    }
}