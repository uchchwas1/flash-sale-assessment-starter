<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable flash-sale item.
 *
 * @property int $id
 * @property string $title
 * @property int $total_stock
 * @property int $available_stock
 */
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'total_stock',
        'available_stock',
    ];

    protected function casts(): array
    {
        return [
            'total_stock' => 'integer',
            'available_stock' => 'integer',
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isSoldOut(): bool
    {
        return $this->available_stock <= 0;
    }
}
