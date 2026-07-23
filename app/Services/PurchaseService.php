<?php

declare(strict_types=1);

namespace App\Services;

use App\Buyers\BuyerRegistryInterface;
use App\Exceptions\DuplicatePurchaseException;
use App\Exceptions\ItemNotFoundException;
use App\Exceptions\SoldOutException;
use App\Models\Order;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

final class PurchaseService
{
    /**
     * How many times to retry the purchase transaction if InnoDB reports a
     * deadlock / lock-wait under heavy contention. Without this, a legitimate
     * winner's transaction could die transiently and the item would be
     * UNDER-sold (stock left > 0). Retrying makes "sell exactly N" reliable.
     */
    private const int MAX_TRANSACTION_ATTEMPTS = 5;

    /**
     * SQLSTATE for an integrity constraint violation. On the orders insert the
     * only reachable one is UNIQUE(item_id, user_id) — the double-purchase guard.
     */
    private const string SQLSTATE_INTEGRITY_VIOLATION = '23000';

    public function __construct(
        private readonly ItemRepositoryInterface $items,
        private readonly OrderRepositoryInterface $orders,
        private readonly BuyerRegistryInterface $buyers,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Claim one unit of an item for a user.
     *
     * @throws ItemNotFoundException item id does not exist
     * @throws SoldOutException no stock remains
     * @throws DuplicatePurchaseException the user already owns this item
     */
    public function purchase(int $itemId, string $userId): Order
    {
        // Fast path: a known repeat buyer is rejected in-memory, before any
        // which stays authoritative via findByItemAndUser + the UNIQUE guard.
        if ($this->buyers->hasPurchased($itemId, $userId)) {
            throw new DuplicatePurchaseException($itemId, $userId);
        }

        if ($this->items->findById($itemId) === null) {
            throw new ItemNotFoundException($itemId);
        }

        // DB fallback duplicate check (covers a cold/unavailable registry).
        if ($this->orders->findByItemAndUser($itemId, $userId) !== null) {
            $this->buyers->remember($itemId, $userId); // backfill the fast path
            throw new DuplicatePurchaseException($itemId, $userId);
        }

        $order = $this->db->transaction(function () use ($itemId, $userId): Order {
            // Atomic, row-locked claim. 0 affected rows => nothing left to sell.
            if ($this->items->decrementAvailableStock($itemId) === 0) {
                throw new SoldOutException($itemId);
            }

            try {
                return $this->orders->create($itemId, $userId);
            } catch (QueryException $e) {
                // Same user raced themselves: the constraint rejected the second
                // insert. Rolling back this transaction restores the unit we just
                // decremented, so no stock is lost.
                if ($this->isUniqueViolation($e)) {
                    throw new DuplicatePurchaseException($itemId, $userId);
                }

                throw $e;
            }
        }, self::MAX_TRANSACTION_ATTEMPTS);

        // Record the buyer so subsequent attempts short-circuit before MySQL.
        $this->buyers->remember($itemId, $userId);

        return $order;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION;
    }
}
