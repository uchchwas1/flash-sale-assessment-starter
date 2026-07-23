<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemApiTest extends TestCase
{
    use RefreshDatabase;

    // ---- GET /items/{id} -------------- //

    public function test_show_returns_stock_status_in_envelope(): void
    {
        $item = Item::factory()->withStock(10)->create();

        $this->getJson("/items/{$item->id}")
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Item retrieved.',
                'data' => [
                    'id' => $item->id,
                    'total_stock' => 10,
                    'available_stock' => 10,
                    'sold' => 0,
                    'is_sold_out' => false,
                ],
            ]);
    }

    public function test_show_returns_404_envelope_for_missing_item(): void
    {
        $this->getJson('/items/999999')
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'Item not found.']);
    }

    public function test_show_returns_404_for_non_numeric_id(): void
    {
        $this->getJson('/items/abc')->assertNotFound();
    }

    // ---- POST /items/{id}/buy ----------------------------------------------

    public function test_buy_succeeds_and_decrements_stock(): void
    {
        $item = Item::factory()->withStock(10)->create();

        $this->postJson("/items/{$item->id}/buy", ['user_id' => 'user_1'])
            ->assertCreated()
            ->assertJson([
                'success' => true,
                'message' => 'Purchase successful.',
                'data' => [
                    'item_id' => $item->id,
                    'user_id' => 'user_1',
                    'remaining_stock' => 9,
                ],
            ]);

        $this->assertSame(9, $item->fresh()->available_stock);
        $this->assertDatabaseHas('orders', ['item_id' => $item->id, 'user_id' => 'user_1']);
    }

    public function test_buy_requires_user_id(): void
    {
        $item = Item::factory()->withStock(10)->create();

        $this->postJson("/items/{$item->id}/buy", [])
            ->assertUnprocessable()
            ->assertJson(['success' => false, 'message' => 'Validation failed.'])
            ->assertJsonValidationErrors('user_id');
    }

    public function test_buy_rejects_blank_user_id(): void
    {
        $item = Item::factory()->withStock(10)->create();

        $this->postJson("/items/{$item->id}/buy", ['user_id' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_buy_on_sold_out_item_returns_409(): void
    {
        $item = Item::factory()->withStock(1)->soldOut()->create();

        $this->postJson("/items/{$item->id}/buy", ['user_id' => 'user_1'])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => 'Item is sold out.']);
    }

    public function test_second_purchase_by_same_user_returns_409(): void
    {
        $item = Item::factory()->withStock(10)->create();
        $this->postJson("/items/{$item->id}/buy", ['user_id' => 'user_1'])->assertCreated();

        $this->postJson("/items/{$item->id}/buy", ['user_id' => 'user_1'])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => 'User has already purchased this item.']);

        // The rejected duplicate must not consume a second unit.
        $this->assertSame(9, $item->fresh()->available_stock);
    }

    public function test_buy_on_missing_item_returns_404(): void
    {
        $this->postJson('/items/999999/buy', ['user_id' => 'user_1'])
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'Item not found.']);
    }

    public function test_wrong_verb_returns_405(): void
    {
        $item = Item::factory()->withStock(10)->create();

        // /items/{id}/buy only accepts POST.
        $this->getJson("/items/{$item->id}/buy")->assertStatus(405);
    }
}
