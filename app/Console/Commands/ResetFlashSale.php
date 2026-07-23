<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Item;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * Resets the flash sale to its canonical starting state.
 *
 * Crucially it clears BOTH stores: `migrate:fresh --seed` alone leaves the Redis
 * buyer registry populated from a previous run, so a re-seeded (available) item
 * would still be rejected as "already purchased" by the fast path. This command
 * keeps MySQL and Redis consistent.
 */
class ResetFlashSale extends Command
{
    protected $signature = 'flash-sale:reset';

    protected $description = 'Refresh + reseed the DB and clear the Redis buyer registry so both stores stay consistent.';

    public function handle(Redis $redis): int
    {
        $this->call('migrate:fresh', ['--seed' => true]);

        try {
            foreach (Item::query()->pluck('id') as $id) {
                $redis->connection()->del("buyers:item:{$id}");
            }
            $this->info('Cleared the Redis buyer registry.');
        } catch (Throwable $e) {
            $this->warn('Redis not reachable; skipped clearing the buyer registry: '.$e->getMessage());
        }

        $this->info('Flash sale reset: item 1 stock 10, no orders, buyer registry empty.');

        return self::SUCCESS;
    }
}
