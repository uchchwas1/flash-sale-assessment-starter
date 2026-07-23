<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ItemNotFoundException;
use App\Http\Requests\BuyItemRequest;
use App\Http\Resources\ItemResource;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Services\PurchaseService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ItemController extends Controller
{
    public function __construct(
        private readonly ItemRepositoryInterface $items,
        private readonly PurchaseService $purchase,
    ) {}

    /**
     * GET /items/{id} — current stock status.
     */
    public function show(int $id): JsonResponse
    {
        $item = $this->items->findById($id);

        if ($item === null) {
            throw new ItemNotFoundException($id);
        }

        // `sold` is sourced from the orders audit trail (the stock columns move
        // together, so they can't report how many units have gone out).
        $item->loadCount('orders');

        return ApiResponse::success(new ItemResource($item), 'Item retrieved.');
    }

    /**
     * POST /items/{id}/buy
     * All failure modes (not found, sold out, duplicate) are raised as domain
     */
    public function buy(BuyItemRequest $request, int $id): JsonResponse
    {
        $order = $this->purchase->purchase($id, $request->userId());

        return ApiResponse::success([
            'order_id' => $order->id,
            'item_id' => $order->item_id,
            'user_id' => $order->user_id,
            'remaining_stock' => $this->items->findById($id)?->available_stock ?? 0,
        ], 'Purchase successful.', Response::HTTP_CREATED);
    }
}
