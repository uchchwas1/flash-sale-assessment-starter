<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Item;
use App\Repositories\Contracts\ItemRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ItemRepositoryInterface $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->items = $this->app->make(ItemRepositoryInterface::class);
    }

    public function test_find_by_id_returns_the_item(): void
    {
        $item = Item::factory()->withStock(5)->create();

        $found = $this->items->findById($item->id);

        $this->assertNotNull($found);
        $this->assertSame($item->id, $found->id);
    }

    public function test_find_by_id_returns_null_for_missing_item(): void
    {
        $this->assertNull($this->items->findById(999999));
    }

    public function test_decrement_claims_a_unit_and_reports_one_affected_row(): void
    {
        $item = Item::factory()->withStock(5)->create();

        $affected = $this->items->decrementAvailableStock($item->id);

        $this->assertSame(1, $affected);
        $this->assertSame(4, $item->fresh()->available_stock);
    }

    public function test_decrement_reports_zero_and_does_not_go_negative_when_sold_out(): void
    {
        $item = Item::factory()->withStock(3)->soldOut()->create(); // total 3, available 0

        $affected = $this->items->decrementAvailableStock($item->id);

        $this->assertSame(0, $affected);
        $this->assertSame(0, $item->fresh()->available_stock);
    }

    public function test_sequential_decrements_claim_exactly_the_available_units(): void
    {
        $item = Item::factory()->withStock(3)->create();

        // Attempt more claims than exist; only 3 should succeed.
        $claims = 0;
        for ($i = 0; $i < 10; $i++) {
            $claims += $this->items->decrementAvailableStock($item->id);
        }

        $this->assertSame(3, $claims, 'exactly the available units are claimable');
        $this->assertSame(0, $item->fresh()->available_stock, 'stock never drops below zero');
    }
}
