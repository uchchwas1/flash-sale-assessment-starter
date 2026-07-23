<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Item;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * Clears the Redis buyer registry (the duplicate-buyer fast-path cache) WITHOUT
 * touching the database. Use this when Redis holds buyers from a previous run
 * but the DB has been reset independently, so the fast path wrongly returns
 * 409 "already purchased" for an item that is actually available.
 */
class ClearBuyerCache extends Command
{
    protected $signature = 'flash-sale:clear-buyers';

    protected $description = 'Clear the Redis buyer registry (fast-path cache) without touching the database.';

    public function handle(Redis $redis): int
    {
        try {
            $connection = $redis->connection();
            $cleared = 0;

            foreach (Item::query()->pluck('id') as $id) {
                $cleared += (int) $connection->del("buyers:item:{$id}");
            }

            $this->info("Cleared the buyer registry for {$cleared} item(s).");
        } catch (Throwable $e) {
            $this->warn('Redis not reachable; nothing to clear: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
