<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Repositories\ItemRepository;
use App\Repositories\OrderRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Repository contract -> implementation bindings, so the service layer
     *
     * @var array<class-string,class-string>
     */
    public array $bindings = [
        ItemRepositoryInterface::class => ItemRepository::class,
        OrderRepositoryInterface::class => OrderRepository::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
