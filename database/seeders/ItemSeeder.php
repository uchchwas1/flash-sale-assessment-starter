<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    /**
     * Seed the single pre-defined flash-sale item (id = 1, 10 units) that the
     */
    public function run(): void
    {
        Item::updateOrCreate(
            ['id' => 1],
            [
                'title' => 'Limited Edition Tech Hoodie',
                'total_stock' => 10,
                'available_stock' => 10,
            ],
        );
    }
}
