<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Contracts\ContractLedgerMath;
use App\Services\Notifications\DueNotificationService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractBalanceDueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Africa/Cairo', 'notifications.spread_enabled' => false]);
        PermissionCatalog::ensureSeeded();
        $this->admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin);
        $this->customer = Customer::query()->create([
            'full_name' => 'عميل الباقي',
            'phone' => '01115556677',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-BAL',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $this->vehicle = Vehicle::query()->create([
            'code' => 'BAL-1',
            'plate' => 'ب ل ق 1',
            'brand' => 'MG',
            'model' => 'ZS',
            'year' => 2024,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => 1000,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);
    }

    public function test_partial_create_requires_balance_due_and_clears_when_settled(): void
    {
        $starts = now()->addDays(2)->startOfDay();
        $ends = $starts->copy()->addDays(3);

        $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => $starts->toDateTimeString(),
            'planned_ends_at' => $ends->toDateTimeString(),
            'payment_amount' => 500,
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $due = now()->addDays(7)->toDateString();
        $created = $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => $starts->toDateTimeString(),
            'planned_ends_at' => $ends->toDateTimeString(),
            'payment_amount' => 500,
            'payment_method' => 'cash',
            'balance_due_on' => $due,
        ])->assertCreated()->json('data');

        $contract = Contract::query()->findOrFail($created['id']);
        $this->assertNotNull($contract->balance_due_on);
        $this->assertTrue(
            abs(Carbon::parse($due)->diffInDays($contract->balance_due_on)) <= 1,
            'balance_due_on should match the requested collection date',
        );

        // Settle remaining
        $remaining = app(ContractLedgerMath::class)->remainingCollectible($contract);
        $this->postJson('/api/accounting/payments', [
            'amount' => $remaining,
            'method' => 'cash',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $contract->id,
        ])->assertCreated();

        $this->assertNull($contract->fresh()->balance_due_on);
    }

    public function test_balance_due_notification_starts_two_days_before(): void
    {
        $contract = Contract::query()->create([
            'number' => 'CNT-DUE-1',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(2),
            'planned_ends_at' => now()->addDays(5),
            'approved_days' => 7,
            'frozen_daily_rate' => 1000,
            'frozen_agreed_deposit' => 0,
            'final_total' => 7000,
            'balance_due_on' => now()->addDays(5)->toDateString(),
        ]);

        $this->postJson('/api/accounting/payments', [
            'amount' => 1000,
            'method' => 'cash',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $contract->id,
            'balance_due_on' => now()->addDays(5)->toDateString(),
        ])->assertCreated();

        $this->travelTo(now()->addDays(2)->setTime(14, 0));
        app(DueNotificationService::class)->scan();
        $this->assertSame(0, Notification::query()->where('title', 'like', '%CNT-DUE-1%')->count());

        $this->travelTo(Carbon::parse($contract->fresh()->balance_due_on)->subDay()->setTime(14, 0));
        app(DueNotificationService::class)->scan();
        $this->assertGreaterThan(0, Notification::query()->where('title', 'like', '%CNT-DUE-1%')->count());
    }
}
