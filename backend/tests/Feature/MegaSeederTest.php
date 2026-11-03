<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Models\WaitlistRequest;
use App\Models\WorkOrder;
use App\Services\Operations\AuditNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MegaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_mega_seeder_hits_volume_targets(): void
    {
        $this->seed();

        $this->assertGreaterThanOrEqual(50, Vehicle::query()->count());
        $this->assertGreaterThanOrEqual(100, Contract::query()->count());
        $this->assertGreaterThanOrEqual(50, Customer::query()->count());
        $this->assertGreaterThanOrEqual(25, Employee::query()->count());
        $this->assertGreaterThanOrEqual(30, WorkOrder::query()->count());
        $this->assertGreaterThanOrEqual(20, Violation::query()->count());
        $this->assertGreaterThanOrEqual(20, Expense::query()->count());
        $this->assertGreaterThanOrEqual(10, WaitlistRequest::query()->count());
        $this->assertGreaterThanOrEqual(200, AuditLog::query()->count());

        $this->assertTrue(Contract::query()->where('status', 'active')->exists());
        $this->assertTrue(Contract::query()->where('status', 'approved')->exists());
        $this->assertTrue(Contract::query()->where('status', 'completed')->exists());
        $this->assertTrue(Contract::query()->where('status', 'cancelled')->exists());
        $this->assertTrue(WorkOrder::query()->where('status', 'closed')->exists());
        $this->assertTrue(Vehicle::query()->where('manual_status', 'maintenance')->exists());

        $sample = AuditLog::query()->where('action', 'handover')->latest('id')->first();
        $this->assertNotNull($sample);
        $summary = app(AuditNarrator::class)->summarize($sample, 'مدير النظام');
        $this->assertStringContainsString('قام', $summary);
    }
}
