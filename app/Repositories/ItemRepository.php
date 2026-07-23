<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Item;
use App\Repositories\Contracts\ItemRepositoryInterface;
use Illuminate\Contracts\Database\Query\Builder;

final class ItemRepository implements ItemRepositoryInterface
{
    public function findById(int $id): ?Item
    {
        return Item::query()->find($id);
    }

    public function decrementAvailableStock(int $itemId): int
    {
        // The `> 0` guard is the linchpin: it fuses the "is stock available?"
        // check and the decrement into one atomic, row-locked statement, so no
        // two concurrent buyers can both pass the check for the same unit.
        // Both stock columns move together (they represent the same current
        // stock); `decrementEach` returns the affected-row count (1 = claimed,
        // 0 = sold out).
        return $this->baseQuery()
            ->where('id', $itemId)
            ->where('available_stock', '>', 0)
            ->decrementEach([
                'available_stock' => 1,
                'total_stock' => 1,
            ]);
    }

    private function baseQuery(): Builder
    {
        return Item::query()->toBase();
    }
}
