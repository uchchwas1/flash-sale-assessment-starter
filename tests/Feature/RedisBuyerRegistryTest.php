<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Buyers\RedisBuyerRegistry;
use Illuminate\Contracts\Redis\Factory as Redis;
use Psr\Log\NullLogger;
use Tests\TestCase;
use Throwable;

class RedisBuyerRegistryTest extends TestCase
{
    private const int ITEM_ID = 987654; // isolated key space for the test

    private RedisBuyerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $redis = $this->app->make(Redis::class);

        try {
            $redis->connection()->del('buyers:item:'.self::ITEM_ID);
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis is not available: '.$e->getMessage());
        }

        $this->registry = new RedisBuyerRegistry($redis, new NullLogger);
    }

    protected function tearDown(): void
    {
        try {
            $this->app->make(Redis::class)->connection()->del('buyers:item:'.self::ITEM_ID);
        } catch (Throwable) {
            // ignore
        }

        parent::tearDown();
    }

    public function test_sismember_reflects_remembered_buyers(): void
    {
        $this->assertFalse($this->registry->hasPurchased(self::ITEM_ID, 'user_1'));

        $this->registry->remember(self::ITEM_ID, 'user_1');

        $this->assertTrue($this->registry->hasPurchased(self::ITEM_ID, 'user_1'));
        $this->assertFalse($this->registry->hasPurchased(self::ITEM_ID, 'user_2'));
    }

    public function test_membership_is_case_sensitive(): void
    {
        $this->registry->remember(self::ITEM_ID, 'user_1');

        // Matches the utf8mb4_bin exact-match semantics of the DB guard.
        $this->assertFalse($this->registry->hasPurchased(self::ITEM_ID, 'User_1'));
    }
}
