<?php

declare(strict_types=1);

namespace App\Buyers;

/**
 * A fast, advisory record of which users have already bought which item.
 * rejected without touching MySQL. 
 * remains the UNIQUE(item_id, user_id) constraint in the database
 */
interface BuyerRegistryInterface
{
    /**
     * Has this user already purchased this item? Must fail "open" (return
     * false) if the backing store is unavailable, so the DB path still runs.
     */
    public function hasPurchased(int $itemId, string $userId): bool;

    /**
     * Record a successful purchase. Best-effort — a failure here must not break
     * the purchase, because the DB constraint already guarantees correctness.
     */
    public function remember(int $itemId, string $userId): void;
}
