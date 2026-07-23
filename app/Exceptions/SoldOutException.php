<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class SoldOutException extends FlashSaleException
{
    public function __construct(public readonly int $itemId)
    {
        parent::__construct('Item is sold out.');
    }

    public function httpStatus(): int
    {
        return Response::HTTP_CONFLICT; // 409
    }
}
