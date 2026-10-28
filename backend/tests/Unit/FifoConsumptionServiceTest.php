<?php

namespace Tests\Unit;

use App\Services\Inventory\FifoConsumptionService;
use DomainException;
use PHPUnit\Framework\TestCase;

class FifoConsumptionServiceTest extends TestCase
{
    public function test_consumes_oldest_batch_first(): void
    {
        $service = new FifoConsumptionService;
        $batches = [
            ['id' => 1, 'remaining' => 2, 'unit_cost' => 100],
            ['id' => 2, 'remaining' => 5, 'unit_cost' => 120],
        ];

        $alloc = $service->consume($batches, 3);

        $this->assertSame([
            ['batch_id' => 1, 'qty' => 2.0, 'unit_cost' => 100.0],
            ['batch_id' => 2, 'qty' => 1.0, 'unit_cost' => 120.0],
        ], $alloc);
        $this->assertSame(320.0, $service->totalCost($alloc));
    }

    public function test_rejects_negative_when_enabled(): void
    {
        $this->expectException(DomainException::class);

        $service = new FifoConsumptionService;
        $service->consume([
            ['id' => 1, 'remaining' => 1, 'unit_cost' => 50],
        ], 5, true);
    }
}
