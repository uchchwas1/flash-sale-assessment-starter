<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Order;

interface OrderRepositoryInterface
{
    /**
     * Persist a purchase record. May throw on the UNIQUE(item_id, user_id)
     * constraint when the same user already owns the item — that violation is
     * the double-purchase guard and is handled by the service layer.
     */
    public function create(int $itemId, string $userId): Order;

    /**
     * Find an existing order for a given (item, user) pair, or null.
     * Used to return an idempotent response when a buyer retries after a
     * purchase that already succeeded.
     */
    public function findByItemAndUser(int $itemId, string $userId): ?Order;
}
