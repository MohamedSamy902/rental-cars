<?php

namespace Tests\Unit;

use App\Services\Fleet\VehicleAvailabilityService;
use DomainException;
use PHPUnit\Framework\TestCase;

class VehicleAvailabilityServiceTest extends TestCase
{
    public function test_status_priority(): void
    {
        $svc = new VehicleAvailabilityService;

        $this->assertSame('rented', $svc->statusOnDate([
            'has_active_handover' => true,
            'has_approved_covering_date' => true,
        ]));

        $this->assertSame('reserved', $svc->statusOnDate([
            'has_approved_covering_date' => true,
        ]));

        $this->assertSame('available', $svc->statusOnDate([]));
    }

    public function test_maintenance_blocked_by_conflicts(): void
    {
        $this->expectException(DomainException::class);
        $svc = new VehicleAvailabilityService;
        $svc->assertCanEnterMaintenance([
            ['id' => 1, 'number' => 'CNT-1'],
        ]);
    }
}
