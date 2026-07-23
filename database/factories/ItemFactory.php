<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        $stock = $this->faker->numberBetween(1, 100);

        return [
            'title' => $this->faker->words(3, true),
            'total_stock' => $stock,
            'available_stock' => $stock,
        ];
    }

    /**
     * Fix the stock to an exact number of available (and total) units.
     */
    public function withStock(int $units): static
    {
        return $this->state(fn (): array => [
            'total_stock' => $units,
            'available_stock' => $units,
        ]);
    }

    /**
     * A fully sold-out item (available_stock = 0).
     */
    public function soldOut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_stock' => 0,
        ]);
    }
}
