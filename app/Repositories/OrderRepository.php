<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Order;
use App\Repositories\Contracts\OrderRepositoryInterface;

final class OrderRepository implements OrderRepositoryInterface
{
    public function create(int $itemId, string $userId): Order
    {
        return Order::query()->create([
            'item_id' => $itemId,
            'user_id' => $userId,
        ]);
    }

    public function findByItemAndUser(int $itemId, string $userId): ?Order
    {
        return Order::query()
            ->where('item_id', $itemId)
            ->where('user_id', $userId)
            ->first();
    }
}
