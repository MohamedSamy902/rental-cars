<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\Notification;
use App\Models\NotificationReminder;
use App\Models\OwnerMonthDue;
use App\Models\Payment;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleOwner;
use App\Models\VehicleTire;
use App\Models\Violation;
use App\Models\WaitlistRequest;
use App\Models\WorkOrder;
use App\Services\Notifications\DueNotificationService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DueNotificationScanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $this->customer = Customer::query()->create([
            'full_name' => 'عميل التنبيه',
            'phone' => '01005556677',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-N',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $this->vehicle = Vehicle::query()->create([
            'code' => 'N-1',
            'plate' => 'ن ب ه 1001',
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
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_command_runs_on_an_empty_queue(): void
    {
        $this->artisan('notifications:scan')
            ->assertSuccessful()
            ->expectsOutputToContain('تنبيهات أُرسلت: 0');

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_booking_window_is_two_days_and_money_due_is_sent(): void
    {
        $sales = User::factory()->create(['is_active' => true]);
        $soon = $this->contract('CNT-SOON', 'active', now()->subDays(2), now()->addDay(), [
            'approved_days' => 3,
            'frozen_daily_rate' => 1000,
            'sales_user_id' => $sales->id,
        ]);
        $later = $this->contract('CNT-LATER', 'active', now()->addDays(6), now()->addDays(9));
        $overdue = $this->contract('CNT-LATE', 'active', now()->subDays(4), now()->subDay(), [
            'final_total' => 1000,
        ]);
        $this->pay($overdue, 1000);
        $pendingSoon = $this->contract('CNT-PEND', 'pending', now()->addDay(), now()->addDays(4), [
            'frozen_agreed_deposit' => 1500,
            'sales_user_id' => $sales->id,
        ]);
        $pendingLater = $this->contract('CNT-PEND-FAR', 'pending', now()->addDays(6), now()->addDays(8), [
            'frozen_agreed_deposit' => 1500,
        ]);
        $owed = $this->contract('CNT-OWED', 'completed', now()->subDays(10), now()->subDays(7), [
            'final_total' => 5000,
        ]);
        $this->pay($owed, 1000);
        $settled = $this->contract('CNT-PAID', 'completed', now()->subDays(20), now()->subDays(17), [
            'final_total' => 2000,
        ]);
        $this->pay($settled, 2000);

        app(DueNotificationService::class)->scan();

        $this->assertSame(2, $this->titlesLike('قرب انتهاء العقد CNT-SOON'));
        $this->assertSame(1, $this->titlesLike('استلام متأخر للعقد CNT-LATE'));
        $this->assertSame(0, $this->titlesLike('CNT-LATER'));
        $this->assertSame(0, $this->titlesLike('CNT-PEND-FAR'));
        $this->assertSame(2, $this->titlesLike('حجز معلّق يبدأ خلال يومين CNT-PEND'));
        $this->assertSame(2, $this->titlesLike('تأمين غير مقبوض على العقد CNT-PEND'));
        $this->assertSame(2, $this->titlesLike('مبلغ مستحق على العقد CNT-SOON'));
        $this->assertSame(1, $this->titlesLike('مبلغ مستحق على العقد CNT-OWED'));
        $this->assertSame(0, $this->titlesLike('CNT-PAID'));
        $this->assertSame(0, $this->titlesLike('مبلغ مستحق على العقد CNT-LATE'));
        $this->assertDatabaseHas('notification_reminders', [
            'rule_key' => 'contract_end',
            'subject_id' => $soon->id,
        ]);
        $this->assertDatabaseMissing('notification_reminders', [
            'rule_key' => 'contract_end',
            'subject_id' => $later->id,
        ]);
        $this->assertDatabaseMissing('notification_reminders', [
            'rule_key' => 'contract_start',
            'subject_id' => $pendingLater->id,
        ]);
        $this->assertDatabaseHas('notification_reminders', [
            'rule_key' => 'contract_start',
            'subject_id' => $pendingSoon->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $sales->id,
            'link_type' => 'contract',
            'link_id' => $soon->id,
        ]);
    }

    public function test_reminder_repeats_after_24_hours_and_stops_when_paid(): void
    {
        $owed = $this->contract('CNT-BAL', 'completed', now()->subDays(8), now()->subDays(5), [
            'final_total' => 4000,
        ]);
        $this->pay($owed, 1000);
        $service = app(DueNotificationService::class);

        $service->scan();
        $first = $this->titlesLike('مبلغ مستحق على العقد CNT-BAL');
        $this->assertSame(1, $first);

        $service->scan();
        $this->assertSame($first, $this->titlesLike('مبلغ مستحق على العقد CNT-BAL'));

        $this->travel(23)->hours();
        $service->scan();
        $this->assertSame($first, $this->titlesLike('مبلغ مستحق على العقد CNT-BAL'));

        $this->travel(2)->hours();
        $service->scan();
        $this->assertSame($first + 1, $this->titlesLike('مبلغ مستحق على العقد CNT-BAL'));

        $this->pay($owed, 3000);
        $service->scan();
        $this->assertDatabaseMissing('notification_reminders', [
            'rule_key' => 'contract_balance',
            'subject_id' => $owed->id,
        ]);
        $settledCount = $this->titlesLike('مبلغ مستحق على العقد CNT-BAL');

        $this->travel(25)->hours();
        $service->scan();
        $this->assertSame($settledCount, $this->titlesLike('مبلغ مستحق على العقد CNT-BAL'));
        $this->travelBack();
    }

    public function test_stock_documents_owner_payroll_and_waitlist(): void
    {
        $low = InventoryItem::query()->create([
            'code' => 'OIL-LOW', 'name' => 'زيت ناقص', 'category' => 'زيوت', 'unit' => 'لتر', 'reorder_level' => 5, 'is_active' => true,
        ]);
        InventoryBatch::query()->create([
            'inventory_item_id' => $low->id,
            'received_on' => now()->toDateString(),
            'quantity' => 2,
            'remaining' => 2,
            'unit_cost' => 80,
        ]);
        $ok = InventoryItem::query()->create([
            'code' => 'OIL-OK', 'name' => 'زيت كافي', 'category' => 'زيوت', 'unit' => 'لتر', 'reorder_level' => 1, 'is_active' => true,
        ]);
        InventoryBatch::query()->create([
            'inventory_item_id' => $ok->id,
            'received_on' => now()->toDateString(),
            'quantity' => 10,
            'remaining' => 10,
            'unit_cost' => 80,
        ]);

        VehicleDocument::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'type' => 'رخصة تسيير',
            'file_path' => 'docs/license.pdf',
            'expires_on' => now()->addDay()->toDateString(),
        ]);
        VehicleDocument::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'type' => 'تأمين',
            'file_path' => 'docs/ins.pdf',
            'expires_on' => now()->addDays(20)->toDateString(),
        ]);
        $this->customer->update(['license_expires_on' => now()->addDay()->toDateString()]);

        VehicleTire::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'position' => 'أمام يمين',
            'installed_on' => now()->subYear()->toDateString(),
            'odometer_at_install' => 6000,
            'expected_life_km' => 4500,
        ]);

        WorkOrder::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'opened_on' => now()->subDays(3)->toDateString(),
            'odometer' => 9000,
            'status' => 'closed',
            'next_service_on' => now()->addDay()->toDateString(),
            'next_service_odometer' => 10400,
        ]);

        Violation::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'occurred_at' => now()->subDay(),
            'amount' => 350,
            'status' => 'new',
        ]);
        Violation::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'occurred_at' => now()->subDays(3),
            'amount' => 100,
            'status' => 'paid',
        ]);

        $owner = VehicleOwner::query()->create(['full_name' => 'مالك التنبيه', 'is_active' => true]);
        OwnerMonthDue::query()->create([
            'owner_id' => $owner->id,
            'vehicle_id' => $this->vehicle->id,
            'year_month' => now()->format('Y-m'),
            'amount' => 2500,
        ]);

        $unpaidEmployee = Employee::query()->create([
            'name' => 'راتب معلّق',
            'base_salary' => 8000,
            'status' => 'active',
        ]);
        $draftEmployee = Employee::query()->create([
            'name' => 'راتب مسودة',
            'base_salary' => 5000,
            'status' => 'active',
        ]);
        $approved = PayrollRun::query()->create([
            'year' => 2026, 'month' => 10, 'status' => 'approved', 'total_amount' => 8000,
        ]);
        PayrollLine::query()->create([
            'payroll_run_id' => $approved->id,
            'employee_id' => $unpaidEmployee->id,
            'base_salary' => 8000,
            'net_amount' => 8000,
            'paid_amount' => 3000,
        ]);
        $draft = PayrollRun::query()->create([
            'year' => 2026, 'month' => 11, 'status' => 'draft', 'total_amount' => 5000,
        ]);
        PayrollLine::query()->create([
            'payroll_run_id' => $draft->id,
            'employee_id' => $draftEmployee->id,
            'base_salary' => 5000,
            'net_amount' => 5000,
            'paid_amount' => 0,
        ]);

        $waiting = WaitlistRequest::query()->create([
            'customer_id' => $this->customer->id,
            'category' => 'اقتصادي',
            'status' => 'waiting',
            'desired_start' => now()->toDateString(),
            'desired_end' => now()->addDays(2)->toDateString(),
            'created_by' => $this->admin->id,
            'expires_at' => now()->addDays(7),
        ]);

        app(DueNotificationService::class)->scan();

        $this->assertSame(1, $this->titlesLike('صنف تحت حد التنبيه: زيت ناقص'));
        $this->assertSame(0, $this->titlesLike('زيت كافي'));
        $this->assertSame(1, $this->titlesLike('مستند عربية يقترب انتهاؤه'));
        $this->assertSame(1, NotificationReminder::query()->where('rule_key', 'vehicle_document')->count());
        $this->assertSame(1, $this->titlesLike('رخصة القيادة تقترب من الانتهاء'));
        $this->assertSame(1, $this->titlesLike('إطار اقترب من نهاية عمره'));
        $this->assertSame(1, $this->titlesLike('صيانة بالتاريخ'));
        $this->assertSame(1, $this->titlesLike('صيانة بالكيلومتر'));
        $this->assertSame(1, NotificationReminder::query()->where('rule_key', 'violation_open')->count());
        $this->assertSame(1, $this->titlesLike('مستحق مالك لم يُصرف: مالك التنبيه'));
        $this->assertSame(1, $this->titlesLike('راتب لم يُصرف: راتب معلّق'));
        $this->assertSame(0, $this->titlesLike('راتب لم يُصرف: راتب مسودة'));
        $this->assertSame(1, $this->titlesLike('تقييم معلّق: راتب معلّق'));
        $this->assertSame(1, $this->titlesLike('سيارة متاحة لطلب انتظار'));
        $waiting->refresh();
        $this->assertSame('notified', $waiting->status);

        Evaluation::query()->create([
            'employee_id' => $unpaidEmployee->id,
            'evaluator_id' => $this->admin->id,
            'score' => 4,
            'period' => now()->format('Y-m'),
        ]);
        Evaluation::query()->create([
            'employee_id' => $draftEmployee->id,
            'evaluator_id' => $this->admin->id,
            'score' => 3,
            'period' => 'ملاحظة',
        ]);
        app(DueNotificationService::class)->scan();
        $this->assertSame(0, NotificationReminder::query()->where('rule_key', 'evaluation_due')->count());
    }

    public function test_only_users_with_the_domain_permission_are_notified(): void
    {
        $stockRole = Role::query()->create(['name' => 'stock_only', 'label' => 'مخزون فقط']);
        $stockRole->permissions()->sync(
            Permission::query()->where('key', 'inventory.view')->pluck('id')
        );
        $stockUser = User::factory()->create(['role_id' => $stockRole->id, 'is_active' => true]);

        $contractRole = Role::query()->create(['name' => 'contracts_only', 'label' => 'عقود فقط']);
        $contractRole->permissions()->sync(
            Permission::query()->where('key', 'contracts.view')->pluck('id')
        );
        $contractUser = User::factory()->create(['role_id' => $contractRole->id, 'is_active' => true]);

        $this->contract('CNT-ROLE', 'active', now()->subDay(), now()->addDay());
        $item = InventoryItem::query()->create([
            'code' => 'PAD', 'name' => 'تيل', 'category' => 'فرامل', 'unit' => 'طقم', 'reorder_level' => 2, 'is_active' => true,
        ]);
        InventoryBatch::query()->create([
            'inventory_item_id' => $item->id,
            'received_on' => now()->toDateString(),
            'quantity' => 1,
            'remaining' => 0,
            'unit_cost' => 50,
        ]);

        app(DueNotificationService::class)->scan();

        $this->assertSame(1, Notification::query()->where('user_id', $contractUser->id)->where('title', 'like', '%CNT-ROLE%')->count());
        $this->assertSame(0, Notification::query()->where('user_id', $stockUser->id)->where('title', 'like', '%CNT-ROLE%')->count());
        $this->assertSame(1, Notification::query()->where('user_id', $stockUser->id)->where('title', 'like', '%تيل%')->count());
        $this->assertSame(0, Notification::query()->where('user_id', $contractUser->id)->where('title', 'like', '%تيل%')->count());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function contract(string $number, string $status, mixed $start, mixed $end, array $extra = []): Contract
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

    private function titlesLike(string $fragment): int
    {
        return Notification::query()->where('title', 'like', '%'.$fragment.'%')->count();
    }
}
