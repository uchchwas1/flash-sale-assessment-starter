<?php

declare(strict_types=1);

namespace App\Providers;

use App\Buyers\ArrayBuyerRegistry;
use App\Buyers\BuyerRegistryInterface;
use App\Buyers\RedisBuyerRegistry;
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
        // The buyer registry is Redis-backed in real environments and in-memory
        // under tests, so the fast-path logic is exercised without a live Redis.
        $this->app->singleton(
            BuyerRegistryInterface::class,
            fn () => $this->app->environment('testing')
                ? new ArrayBuyerRegistry
                : $this->app->make(RedisBuyerRegistry::class),
        );
    }

    public function boot(): void
    {
        //
    }
}
