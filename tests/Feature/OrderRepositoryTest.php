<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Item;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private OrderRepositoryInterface $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = $this->app->make(OrderRepositoryInterface::class);
    }

    public function test_create_persists_an_order(): void
    {
        $item = Item::factory()->withStock(5)->create();

        $order = $this->orders->create($item->id, 'user_1');

        $this->assertSame($item->id, $order->item_id);
        $this->assertSame('user_1', $order->user_id);
        $this->assertDatabaseHas('orders', ['item_id' => $item->id, 'user_id' => 'user_1']);
    }

    public function test_duplicate_purchase_violates_the_unique_constraint(): void
    {
        $item = Item::factory()->withStock(5)->create();
        $this->orders->create($item->id, 'user_1');

        $this->expectException(QueryException::class);
        $this->orders->create($item->id, 'user_1'); // same (item, user) -> rejected
    }

    public function test_find_by_item_and_user_returns_existing_or_null(): void
    {
        $item = Item::factory()->withStock(5)->create();
        $this->orders->create($item->id, 'user_1');

        $this->assertNotNull($this->orders->findByItemAndUser($item->id, 'user_1'));
        $this->assertNull($this->orders->findByItemAndUser($item->id, 'user_2'));
    }

    public function test_user_id_dedupe_is_case_sensitive(): void
    {
        $item = Item::factory()->withStock(5)->create();
        $this->orders->create($item->id, 'user_1');
        $order = $this->orders->create($item->id, 'User_1');

        $this->assertSame('User_1', $order->user_id);
        $this->assertDatabaseCount('orders', 2);
    }
}
