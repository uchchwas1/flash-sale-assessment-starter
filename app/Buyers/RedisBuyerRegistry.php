<?php

declare(strict_types=1);

namespace App\Buyers;

use Illuminate\Contracts\Redis\Factory as Redis;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Redis-backed buyer registry: one SET per item (`buyers:item:{id}`) whose
 * members are the user ids that have purchased it.
 *
 *   hasPurchased -> SISMEMBER buyers:item:{id} {userId}
 *   remember     -> SADD      buyers:item:{id} {userId}
 *
 * Both operations degrade gracefully: if Redis is unreachable the fast path is
 * simply skipped and the DB (findByItemAndUser + UNIQUE constraint) takes over.
 */
final class RedisBuyerRegistry implements BuyerRegistryInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    public function hasPurchased(int $itemId, string $userId): bool
    {
        try {
            return (bool) $this->redis->connection()->sismember($this->key($itemId), $userId);
        } catch (Throwable $e) {
            // Fail open: let the authoritative DB path decide.
            $this->logger->warning('Buyer registry lookup failed; falling back to DB.', [
                'item_id' => $itemId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function remember(int $itemId, string $userId): void
    {
        try {
            $this->redis->connection()->sadd($this->key($itemId), [$userId]);
        } catch (Throwable $e) {
            $this->logger->warning('Buyer registry write failed; DB remains authoritative.', [
                'item_id' => $itemId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function key(int $itemId): string
    {
        return "buyers:item:{$itemId}";
    }
}
