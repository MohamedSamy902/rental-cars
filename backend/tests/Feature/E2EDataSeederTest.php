<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\OwnerMonthDue;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\SalaryAdvance;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\Violation;
use App\Models\WaitlistRequest;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Services\Operations\AuditNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class E2EDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_fills_every_major_module(): void
    {
        $this->seed();

        $this->assertGreaterThanOrEqual(10, Vehicle::query()->count());
        $this->assertGreaterThanOrEqual(8, Contract::query()->count());
        $this->assertGreaterThanOrEqual(5, Customer::query()->count());
        $this->assertTrue(Contract::query()->where('number', 'CNT-TODAY-01')->where('status', 'approved')->exists());
        $this->assertTrue(Contract::query()->where('number', 'CNT-DONE-01')->where('status', 'completed')->exists());
        $this->assertTrue(Contract::query()->where('number', 'CNT-OWED-01')->where('status', 'completed')->exists());
        $this->assertTrue(Contract::query()->where('status', 'active')->exists());
        $this->assertTrue(Payment::query()->exists());

        $this->assertGreaterThanOrEqual(3, WorkOrder::query()->count());
        $this->assertTrue(WorkOrderLine::query()->where('part_source', 'stock')->exists());
        $this->assertTrue(WorkOrderLine::query()->where('from_petty_cash', true)->exists());
        $this->assertTrue(WorkOrder::query()->whereNotNull('next_service_on')->exists());

        $this->assertGreaterThanOrEqual(3, Employee::query()->count());
        $this->assertGreaterThanOrEqual(3, SalaryAdvance::query()->count());
        $this->assertTrue(PayrollRun::query()->exists());
        $this->assertTrue(EmployeeAssignment::query()->exists());

        $this->assertTrue(Violation::query()->exists());
        $this->assertTrue(Expense::query()->exists());
        $this->assertTrue(WaitlistRequest::query()->exists());
        $this->assertTrue(OwnerMonthDue::query()->exists());
        $this->assertTrue(VehicleDocument::query()->exists());
        $this->assertTrue(InventoryItem::query()->where('code', 'CLT-GRN')->exists());

        $this->assertGreaterThanOrEqual(8, AuditLog::query()->count());
        $sample = AuditLog::query()->where('action', 'handover')->first();
        $this->assertNotNull($sample);
        $summary = app(AuditNarrator::class)->summarize($sample, 'مدير النظام');
        $this->assertStringContainsString('قام', $summary);
        $this->assertStringContainsString('تسليم', $summary);
    }
}
