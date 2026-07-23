<?php

declare(strict_types=1);

namespace App\Buyers;

/**
 * In-memory buyer registry. Used in the test suite (and as a zero-dependency
 * fallback) so the fast-path logic can be exercised without a running Redis.
 */
final class ArrayBuyerRegistry implements BuyerRegistryInterface
{
    /** @var array<string, true> */
    private array $seen = [];

    public function hasPurchased(int $itemId, string $userId): bool
    {
        return isset($this->seen[$this->key($itemId, $userId)]);
    }

    public function remember(int $itemId, string $userId): void
    {
        $this->seen[$this->key($itemId, $userId)] = true;
    }

    private function key(int $itemId, string $userId): string
    {
        return $itemId.'|'.$userId;
    }
}
