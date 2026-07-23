<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base for domain-level failures in the flash-sale flow.
 */
abstract class FlashSaleException extends RuntimeException
{
    /**
     * HTTP status this domain error maps to.
     */
    abstract public function httpStatus(): int;

    /**
     * Client-safe message for the response envelope.
     */
    public function errorMessage(): string
    {
        return $this->getMessage();
    }
}
