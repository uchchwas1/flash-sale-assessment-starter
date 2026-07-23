<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'total_stock' => $this->total_stock,
            'available_stock' => $this->available_stock,
            'sold' => (int) ($this->orders_count ?? 0),
            'is_sold_out' => $this->isSoldOut(),
        ];
    }
}
