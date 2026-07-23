<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\DuplicatePurchaseException;
use App\Exceptions\SoldOutException;
use App\Services\PurchaseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * The headline guarantee, proven under genuine OS-level parallelism.
 *
 * pcntl_fork spawns TOTAL_USERS real processes that all hammer the same item
 * row at once — a far harsher race than a single-threaded loop. We assert that
 * the DB atomic-decrement + UNIQUE guard let through exactly STOCK winners and
 * never oversell, even while the deadlock-retry (E1) absorbs lock contention.
 *
 * Note: this test does NOT use RefreshDatabase — forked children each need
 * their own committed view of the data, which transactions would hide. State
 * is therefore set up and torn down explicitly.
 *
 * @requires extension pcntl
 */
class PurchaseConcurrencyTest extends TestCase
{
    private const int STOCK = 10;
    private const int TOTAL_USERS = 50;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        $this->resetItem();
    }

    protected function tearDown(): void
    {
        $this->truncateAll();
        parent::tearDown();
    }

    public function test_concurrent_buyers_never_oversell_the_item(): void
    {
        if (! \function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is required for the concurrency test.');
        }

        $pids = [];

        for ($i = 1; $i <= self::TOTAL_USERS; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork process.');
            }

            if ($pid === 0) {
                // ---- child: its own connection, one purchase attempt ----
                exit($this->attemptPurchaseAsChild("user_$i"));
            }

            $pids[] = $pid;
        }

        // ---- parent: collect each child's outcome via its exit code ----
        $success = 0;
        $soldOut = 0;
        $duplicate = 0;
        $errored = 0;

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            match (pcntl_wexitstatus($status)) {
                0 => $success++,
                1 => $soldOut++,
                2 => $duplicate++,
                default => $errored++,
            };
        }

        // Verify from a fresh connection, independent of the forked children.
        [$stock, $orders, $distinctUsers] = $this->finalState();

        $this->assertSame(0, $errored, 'no request should error out');
        $this->assertSame(0, $duplicate, 'distinct users -> no duplicates expected');
        $this->assertSame(self::STOCK, $success, 'exactly the stock is sold');
        $this->assertSame(self::TOTAL_USERS - self::STOCK, $soldOut, 'everyone else is rejected');

        $this->assertSame(0, $stock, 'stock never drops below zero and is fully consumed');
        $this->assertSame(self::STOCK, $orders, 'orders created never exceed stock');
        $this->assertSame(self::STOCK, $distinctUsers, 'no user bought twice');
    }

    /**
     * Runs inside a forked child. Returns an exit code describing the outcome.
     */
    private function attemptPurchaseAsChild(string $userId): int
    {
        // The child inherited the parent's DB handle; drop it so this process
        // opens its own connection rather than corrupting a shared socket.
        DB::disconnect();

        try {
            $this->app->make(PurchaseService::class)->purchase(1, $userId);

            return 0;
        } catch (SoldOutException) {
            return 1;
        } catch (DuplicatePurchaseException) {
            return 2;
        } catch (\Throwable) {
            return 3;
        }
    }

    private function resetItem(): void
    {
        $this->truncateAll();
        DB::table('items')->insert([
            'id' => 1,
            'title' => 'Limited Edition Tech Hoodie',
            'total_stock' => self::STOCK,
            'available_stock' => self::STOCK,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function truncateAll(): void
    {
        DB::table('orders')->delete();
        DB::table('items')->delete();
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function finalState(): array
    {
        DB::disconnect();
        $pdo = new PDO(
            'mysql:host='.config('database.connections.mysql.host')
                .';port='.config('database.connections.mysql.port')
                .';dbname='.config('database.connections.mysql.database'),
            (string) config('database.connections.mysql.username'),
            (string) config('database.connections.mysql.password'),
        );

        return [
            (int) $pdo->query('SELECT available_stock FROM items WHERE id = 1')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE item_id = 1')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM orders WHERE item_id = 1')->fetchColumn(),
        ];
    }
}
