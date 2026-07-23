<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\DuplicatePurchaseException;
use App\Exceptions\ItemNotFoundException;
use App\Exceptions\SoldOutException;
use App\Models\Item;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(PurchaseService::class);
    }

    public function test_successful_purchase_creates_order_and_decrements_stock(): void
    {
        $item = Item::factory()->withStock(5)->create();

        $order = $this->service->purchase($item->id, 'user_1');

        $this->assertSame($item->id, $order->item_id);
        $this->assertSame('user_1', $order->user_id);
        $this->assertSame(4, $item->fresh()->available_stock);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_missing_item_throws_not_found(): void
    {
        $this->expectException(ItemNotFoundException::class);
        $this->service->purchase(999999, 'user_1');
    }

    public function test_sold_out_item_throws_and_leaves_stock_at_zero(): void
    {
        $item = Item::factory()->withStock(1)->soldOut()->create();

        try {
            $this->service->purchase($item->id, 'user_1');
            $this->fail('Expected SoldOutException.');
        } catch (SoldOutException) {
            // expected
        }

        $this->assertSame(0, $item->fresh()->available_stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_duplicate_purchase_is_rejected_and_stock_is_not_consumed(): void
    {
        $item = Item::factory()->withStock(5)->create();
        $this->service->purchase($item->id, 'user_1'); // first buy: stock -> 4

        try {
            $this->service->purchase($item->id, 'user_1'); // second buy: rejected
            $this->fail('Expected DuplicatePurchaseException.');
        } catch (DuplicatePurchaseException) {
            // expected
        }

        // The duplicate must NOT have consumed a second unit.
        $this->assertSame(4, $item->fresh()->available_stock);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_only_available_units_can_be_sold_across_distinct_users(): void
    {
        $item = Item::factory()->withStock(3)->create();

        $success = 0;
        $soldOut = 0;
        for ($i = 1; $i <= 10; $i++) {
            try {
                $this->service->purchase($item->id, "user_$i");
                $success++;
            } catch (SoldOutException) {
                $soldOut++;
            }
        }

        $this->assertSame(3, $success, 'exactly the stock is sellable');
        $this->assertSame(7, $soldOut);
        $this->assertSame(0, $item->fresh()->available_stock);
        $this->assertDatabaseCount('orders', 3);
    }
}
