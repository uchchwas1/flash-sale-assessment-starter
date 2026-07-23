<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Item;

interface ItemRepositoryInterface
{
    /**
     * Fetch an item by primary key, or null when it does not exist.
     */
    public function findById(int $id): ?Item;

    /**
     * Atomically claim one unit of stock.
     *
     * Emits a single guarded statement:
     *   UPDATE items SET available_stock = available_stock - 1
     *   WHERE id = ? AND available_stock > 0
     *
     * The DB serializes concurrent writers on the row and the WHERE guard makes
     * over-decrement impossible, so the affected-row count is the source of truth:
     *
     * @return int 1 when a unit was claimed, 0 when the item was already sold out.
     */
    public function decrementAvailableStock(int $itemId): int;
}
