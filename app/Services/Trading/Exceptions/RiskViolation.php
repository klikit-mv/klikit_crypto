<?php

namespace App\Services\Trading\Exceptions;

use RuntimeException;

/** A trading instruction was blocked by the risk engine (or by insufficient funds). */
class RiskViolation extends RuntimeException
{
    public function __construct(public readonly string $rule, string $message)
    {
        parent::__construct($message);
    }
}
