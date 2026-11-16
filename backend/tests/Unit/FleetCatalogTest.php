<?php

namespace Tests\Unit;

use App\Support\FleetCatalog;
use PHPUnit\Framework\TestCase;

class FleetCatalogTest extends TestCase
{
    public function test_pair_for_index_is_coherent(): void
    {
        for ($n = 0; $n < 40; $n++) {
            $pair = FleetCatalog::pairForIndex($n);
            $this->assertTrue(
                FleetCatalog::modelBelongsToBrand($pair['brand'], $pair['model']),
                "{$pair['brand']} + {$pair['model']} must match",
            );
        }
    }

    public function test_sunny_is_nissan_not_mg(): void
    {
        $this->assertFalse(FleetCatalog::modelBelongsToBrand('MG', 'صني'));
        $this->assertTrue(FleetCatalog::modelBelongsToBrand('نيسان', 'صني'));
        $this->assertTrue(FleetCatalog::modelBelongsToBrand('MG', 'ZS'));
    }
}
