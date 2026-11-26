<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\Accounting\AccountingService;
use App\Services\Media\PublicMediaLinkService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractInvoiceShareTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        config(['app.url' => 'http://localhost', 'app.public_url' => 'http://localhost']);
        TenantSetting::query()->create([
            'center_name' => 'شركة اختبار فاتورة',
            'feature_flags' => [],
            'operational' => [
                'print_commercial_register' => '12345',
            ],
        ]);

        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'full_name' => 'عميل فاتورة',
            'phone' => '01001112233',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-INV',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-INV',
            'plate' => 'س ص 9876',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 12000,
            'odometer_source' => 'manual',
            'daily_rate' => 800,
            'default_deposit' => 1500,
            'is_active' => true,
        ]);

        $this->contract = Contract::query()->create([
            'number' => 'C-INV-100',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(2),
            'planned_ends_at' => now()->addDays(5),
            'approved_days' => 7,
            'frozen_daily_rate' => 800,
            'frozen_agreed_deposit' => 1500,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'period_rent' => 5600,
            'odometer_out' => 12000,
        ]);

        Payment::query()->create([
            'direction' => 'in',
            'amount' => 2000,
            'method' => 'cash',
            'link_type' => 'contract',
            'link_id' => $this->contract->id,
            'paid_at' => now()->subDay(),
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_prepare_invoice_share_returns_signed_url_and_whatsapp_text(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/contracts/'.$this->contract->id.'/invoice/share')
            ->assertOk()
            ->assertJsonPath('data.invoice_number', 'INV-INV-100')
            ->assertJsonPath('data.customer_phone', '01001112233');

        $expiresInDays = (int) $res->json('data.expires_in_days');
        $this->assertGreaterThanOrEqual(30, $expiresInDays, 'صلاحية الرابط ≥ ٣٠ يوماً / حتى شهر بعد نهاية الإيجار');

        $shareUrl = (string) $res->json('data.share_url');
        $message = (string) $res->json('data.message_text');

        $this->assertStringContainsString('/api/public/contracts/invoice', $shareUrl);
        $this->assertStringContainsString('signature=', $shareUrl);
        $this->assertStringContainsString('افتح الفاتورة من الرابط:', $message);
        $this->assertStringContainsString($shareUrl, $message);
        $this->assertStringContainsString('5,600', $message);
        $this->assertStringContainsString('2,000', $message);
        $this->assertStringNotContainsString('صالح لمدة', $message);
        $this->assertStringNotContainsString('فاتورة مؤقتة', $message);
    }

    public function test_public_invoice_page_opens_without_login(): void
    {
        $ttl = app(\App\Services\Contracts\ContractInvoiceShareService::class)
            ->invoiceShareTtlDays($this->contract);
        $url = app(PublicMediaLinkService::class)->temporaryContractInvoiceShareUrl($this->contract->id, $ttl);
        $path = $this->pathAndQuery($url);

        $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('فاتورة', false)
            ->assertSee('عميل فاتورة', false)
            ->assertSee('INV-INV-100', false)
            ->assertSee('كورولا', false)
            ->assertSee('rpm-invoice-v3', false)
            ->assertSee('5,600', false)
            ->assertSee('تم الإنشاء بواسطة', false)
            ->assertSee('محمد سامي', false)
            ->assertSee('wa.me/201150100104', false)
            ->assertDontSee('فاتورة مؤقتة عبر واتساب', false)
            ->assertDontSee('صالحة', false)
            ->assertDontSee('share-banner', false);
    }

    public function test_invoice_share_ttl_extends_one_month_past_planned_end(): void
    {
        $service = app(\App\Services\Contracts\ContractInvoiceShareService::class);
        $expires = $service->invoiceShareExpiresAt($this->contract);
        $plannedEnd = $this->contract->planned_ends_at->copy()->addMonth()->endOfDay();

        $this->assertTrue(
            $expires->greaterThanOrEqualTo($plannedEnd->copy()->subDay()),
            'انتهاء الرابط ≈ شهر بعد نهاية الإيجار'
        );
        $this->assertGreaterThanOrEqual(30, $service->invoiceShareTtlDays($this->contract));
    }

    public function test_public_invoice_rejects_missing_signature(): void
    {
        $this->get('/api/public/contracts/invoice?id='.$this->contract->id)
            ->assertForbidden();
    }

    public function test_expired_invoice_signature_is_rejected(): void
    {
        $url = URL::temporarySignedRoute(
            'public.contracts.invoice',
            now()->subMinute(),
            ['id' => $this->contract->id],
            absolute: false,
        );

        $this->get($this->pathAndQuery($url))
            ->assertForbidden();
    }

    public function test_public_invoice_includes_open_violations_in_total(): void
    {
        Violation::query()->create([
            'vehicle_id' => $this->contract->vehicle_id,
            'customer_id' => $this->contract->customer_id,
            'contract_id' => $this->contract->id,
            'occurred_at' => now()->subDay(),
            'amount' => 450,
            'status' => 'assigned',
            'location' => 'القاهرة',
            'created_by' => $this->admin->id,
        ]);
        Violation::query()->create([
            'vehicle_id' => $this->contract->vehicle_id,
            'customer_id' => $this->contract->customer_id,
            'contract_id' => $this->contract->id,
            'occurred_at' => now()->subDays(2),
            'amount' => 200,
            'status' => 'confirmed',
            'confirmed_at' => now()->subDay(),
            'confirmed_by' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
        $share = $this->postJson('/api/contracts/'.$this->contract->id.'/invoice/share')->assertOk();
        $message = (string) $share->json('data.message_text');
        // rent 5600 + open violation 450 = 6050; paid 2000 → remaining 4050
        $this->assertStringContainsString('6,050', $message);
        $this->assertStringContainsString('مخالفات: 2', $message);
        $this->assertStringContainsString('450', $message);

        $ttl = app(\App\Services\Contracts\ContractInvoiceShareService::class)
            ->invoiceShareTtlDays($this->contract);
        $url = app(PublicMediaLinkService::class)->temporaryContractInvoiceShareUrl($this->contract->id, $ttl);

        $this->get($this->pathAndQuery($url))
            ->assertOk()
            ->assertSee('مخالفة مرورية', false)
            ->assertSee('450', false)
            ->assertSee('مسدد', false)
            ->assertSee('6,050', false)
            ->assertSee('القاهرة', false);
    }

    private function pathAndQuery(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }
}
