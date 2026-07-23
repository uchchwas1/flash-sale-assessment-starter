<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

final class DuplicatePurchaseException extends FlashSaleException
{
    public function __construct(
        public readonly int $itemId,
        public readonly string $userId,
    ) {
        parent::__construct('User has already purchased this item.');
    }

    public function httpStatus(): int
    {
        return Response::HTTP_CONFLICT; // 409
    }
}
