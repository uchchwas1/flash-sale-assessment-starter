<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Buyers\ArrayBuyerRegistry;
use PHPUnit\Framework\TestCase;

class BuyerRegistryTest extends TestCase
{
    public function test_remembers_and_recognises_a_buyer(): void
    {
        $registry = new ArrayBuyerRegistry;

        $this->assertFalse($registry->hasPurchased(1, 'user_1'));

        $registry->remember(1, 'user_1');

        $this->assertTrue($registry->hasPurchased(1, 'user_1'));
    }

    public function test_scopes_membership_per_item_and_per_user(): void
    {
        $registry = new ArrayBuyerRegistry;
        $registry->remember(1, 'user_1');

        $this->assertFalse($registry->hasPurchased(2, 'user_1'), 'different item');
        $this->assertFalse($registry->hasPurchased(1, 'user_2'), 'different user');
    }
}
