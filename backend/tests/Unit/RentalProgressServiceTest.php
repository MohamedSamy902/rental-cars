<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\Contracts\RentalProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private RentalProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RentalProgressService;
    }

    public function test_normal_progress_mid_rental(): void
    {
        $c = $this->makeContract([
            'status' => 'active',
            'starts_at' => now()->subDays(3),
            'planned_ends_at' => now()->addDays(3),
        ]);
        $p = $this->service->forContract($c);
        $this->assertSame('normal', $p['tone']);
        $this->assertGreaterThan(40, $p['percent']);
        $this->assertLessThan(70, $p['percent']);
        $this->assertSame('نهاية المدة', $p['limiting_label']);
    }

    public function test_warning_under_48_hours(): void
    {
        $c = $this->makeContract([
            'status' => 'active',
            'starts_at' => now()->subDays(5),
            'planned_ends_at' => now()->addHours(30),
        ]);
        $p = $this->service->forContract($c);
        $this->assertSame('warning', $p['tone']);
    }

    public function test_overdue_danger(): void
    {
        $c = $this->makeContract([
            'status' => 'active',
            'starts_at' => now()->subDays(10),
            'planned_ends_at' => now()->subDays(2),
        ]);
        $p = $this->service->forContract($c);
        $this->assertSame('danger', $p['tone']);
        $this->assertGreaterThanOrEqual(1, $p['overdue_days']);
        $this->assertStringContainsString('متأخر', $p['remaining_label']);
    }

    public function test_license_limits_effective_end(): void
    {
        $c = $this->makeContract([
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->addDays(10),
        ], licenseExpires: now()->addDays(2)->toDateString());
        $p = $this->service->forContract($c);
        $this->assertSame('انتهاء الرخصة', $p['limiting_label']);
    }

    public function test_open_maintenance_marker(): void
    {
        $c = $this->makeContract([
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->addDays(10),
        ]);
        WorkOrder::query()->create([
            'vehicle_id' => $c->vehicle_id,
            'opened_on' => now()->addDays(1)->toDateString(),
            'odometer' => 1000,
            'status' => 'open',
            'puts_in_maintenance' => true,
            'labor_cost' => 0,
            'parts_cost' => 0,
        ]);
        $p = $this->service->forContract($c->fresh(['customer', 'vehicle']));
        $this->assertSame('صيانة مفتوحة', $p['limiting_label']);
    }

    public function test_same_day_and_cancelled(): void
    {
        $same = $this->makeContract([
            'status' => 'active',
            'starts_at' => now()->startOfDay()->addHours(8),
            'planned_ends_at' => now()->startOfDay()->addHours(20),
        ]);
        $p = $this->service->forContract($same, now()->startOfDay()->addHours(14));
        $this->assertGreaterThan(0, $p['percent']);

        $cancelled = $this->makeContract(['status' => 'cancelled', 'starts_at' => now()->subDay(), 'planned_ends_at' => now()->addDay()]);
        $this->assertSame('muted', $this->service->forContract($cancelled)['tone']);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeContract(array $attrs, ?string $licenseExpires = null): Contract
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل اختبار',
            'phone' => '01012345678',
            'birth_date' => '1990-01-01',
            'license_number' => 'L-1',
            'license_expires_on' => $licenseExpires ?? now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'T-'.uniqid(),
            'plate' => 'ط س ت '.random_int(1000, 9999),
            'brand' => 'كيا',
            'model' => 'ريو',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);

        return Contract::query()->create([
            'number' => 'CNT-'.uniqid(),
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => $attrs['status'] ?? 'active',
            'starts_at' => $attrs['starts_at'],
            'planned_ends_at' => $attrs['planned_ends_at'],
            'frozen_daily_rate' => 500,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_agreed_deposit' => 1000,
            'discount' => 0,
            'other_fees' => 0,
        ])->fresh(['customer', 'vehicle']);
    }
}
