<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'user_id' => 'user_'.$this->faker->unique()->numberBetween(1, 1_000_000),
        ];
    }
}
