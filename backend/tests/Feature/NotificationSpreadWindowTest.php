<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Notifications\DueAlert;
use App\Services\Notifications\DueNotificationService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSpreadWindowTest extends TestCase
{
    use RefreshDatabase;

    private DueNotificationService $service;

    private User $admin;

    private Customer $customer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.timezone' => 'Africa/Cairo',
            'notifications.spread_enabled' => true,
            'notifications.spread_start_hour' => 13,
            'notifications.spread_hours' => 12,
        ]);
        PermissionCatalog::ensureSeeded();
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $this->customer = Customer::query()->create([
            'full_name' => 'عميل التوزيع',
            'phone' => '01009998877',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-SP',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $this->vehicle = Vehicle::query()->create([
            'code' => 'SP-1',
            'plate' => 'س ب ر 1001',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 1000,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);
        $this->service = app(DueNotificationService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_window_slots_cover_thirteen_to_midnight_only(): void
    {
        $this->assertSame(0, $this->service->currentSpreadSlot(Carbon::parse('2026-11-10 13:05', 'Africa/Cairo')));
        $this->assertSame(11, $this->service->currentSpreadSlot(Carbon::parse('2026-11-11 00:30', 'Africa/Cairo')));
        $this->assertNull($this->service->currentSpreadSlot(Carbon::parse('2026-11-10 12:59', 'Africa/Cairo')));
        $this->assertNull($this->service->currentSpreadSlot(Carbon::parse('2026-11-11 01:00', 'Africa/Cairo')));
        $this->assertNull($this->service->currentSpreadSlot(Carbon::parse('2026-11-10 09:00', 'Africa/Cairo')));
    }

    public function test_alert_slot_is_stable(): void
    {
        $alert = new DueAlert(
            ruleKey: 'contract_balance',
            subjectType: 'contract',
            subjectId: 42,
            title: 't',
            body: 'b',
            linkType: 'contract',
            linkId: 42,
            permissions: ['contracts.view'],
        );

        $a = $this->service->alertSpreadSlot($alert);
        $b = $this->service->alertSpreadSlot($alert);
        $this->assertSame($a, $b);
        $this->assertGreaterThanOrEqual(0, $a);
        $this->assertLessThan(12, $a);
    }

    public function test_scan_sends_only_matching_slot_inside_window(): void
    {
        $owed = $this->contract('CNT-SPREAD', 'completed', now()->subDays(8), now()->subDays(5), [
            'final_total' => 4000,
        ]);
        $this->pay($owed, 1000);

        $alert = new DueAlert(
            ruleKey: 'contract_balance',
            subjectType: 'contract',
            subjectId: (int) $owed->id,
            title: "مبلغ مستحق على العقد {$owed->number}",
            body: 'x',
            linkType: 'contract',
            linkId: (int) $owed->id,
            permissions: ['contracts.view', 'accounts.view'],
        );
        $targetSlot = $this->service->alertSpreadSlot($alert);
        $start = $this->service->spreadStartHour();
        $targetHour = ($start + $targetSlot) % 24;

        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'Africa/Cairo'));
        $this->service->scan();
        $this->assertSame(0, Notification::query()->where('title', 'like', '%CNT-SPREAD%')->count());

        $wrongOffset = ($targetSlot + 1) % 12;
        $wrongHour = ($start + $wrongOffset) % 24;
        $this->travelTo(Carbon::parse(sprintf('2026-11-10 %02d:15', $wrongHour), 'Africa/Cairo'));
        $this->service->scan();
        $this->assertSame(0, Notification::query()->where('title', 'like', '%CNT-SPREAD%')->count());

        $this->travelTo(Carbon::parse(sprintf('2026-11-10 %02d:20', $targetHour), 'Africa/Cairo'));
        $this->service->scan();
        $this->assertGreaterThan(0, Notification::query()->where('title', 'like', '%CNT-SPREAD%')->count());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function contract(string $number, string $status, Carbon $start, Carbon $end, array $extra = []): Contract
    {
        return Contract::query()->create([
            'number' => $number,
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => $status,
            'starts_at' => $start,
            'planned_ends_at' => $end,
            ...$extra,
        ]);
    }

    private function pay(Contract $contract, int $amount): void
    {
        Payment::query()->create([
            'amount' => $amount,
            'paid_at' => now(),
            'method' => 'cash',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $contract->id,
            'user_id' => $this->admin->id,
        ]);
    }
}
