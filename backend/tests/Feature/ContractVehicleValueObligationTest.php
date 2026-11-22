<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Services\Documents\DocumentTemplateService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractVehicleValueObligationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        app(DocumentTemplateService::class)->ensureDefaults();
        TenantSetting::query()->create([
            'center_name' => 'اختبار ثمن العربية',
            'feature_flags' => [],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create([
            'role_id' => $admin->id,
            'is_active' => true,
            'name' => 'مدير العقود',
        ]));
    }

    public function test_create_without_obligation_strips_clause_from_print(): void
    {
        [$customerId, $vehicleId] = $this->seedParty(450000);

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'starts_at' => now()->addDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(3)->toIso8601String(),
            'include_vehicle_value_obligation' => false,
        ])->assertCreated()->json('data.id');

        $contract = Contract::query()->findOrFail($id);
        $this->assertFalse((bool) $contract->include_vehicle_value_obligation);
        $this->assertNull($contract->frozen_vehicle_value_text);

        $print = $this->getJson("/api/contracts/{$id}/print")->assertOk();
        $contractHtml = (string) $print->json('data.templates.contract.rendered_html');
        $ackHtml = (string) $print->json('data.templates.handover_receipt.rendered_html');

        $this->assertStringContainsString('rpm-paper-v6', $contractHtml);
        $this->assertStringNotContainsString('البند السادس', $contractHtml);
        $this->assertStringNotContainsString('rpm-vehicle-value-clause', $contractHtml);
        $this->assertStringNotContainsString('450000', $contractHtml);

        $this->assertStringContainsString('rpm-ack-v8', $ackHtml);
        $this->assertStringContainsString('خائنا للأمانة', $ackHtml);
        $this->assertStringNotContainsString('ملزم بسداد ثمن السيارة', $ackHtml);
        $this->assertStringNotContainsString('450000', $ackHtml);
    }

    public function test_create_with_free_text_prints_on_contract_and_ack_only(): void
    {
        [$customerId, $vehicleId] = $this->seedParty(900000);
        $text = '50% من قيمة السيارة وهو مبلغ وقدره 500.000 فقط خمسمائة ألف جنيه';

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'starts_at' => now()->addDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(3)->toIso8601String(),
            'include_vehicle_value_obligation' => true,
            'vehicle_value_text' => $text,
        ])->assertCreated()->json('data.id');

        $contract = Contract::query()->findOrFail($id);
        $this->assertTrue((bool) $contract->include_vehicle_value_obligation);
        $this->assertSame($text, $contract->frozen_vehicle_value_text);

        Vehicle::query()->whereKey($vehicleId)->update(['purchase_value_estimate' => 111111]);

        $print = $this->getJson("/api/contracts/{$id}/print")->assertOk();
        $contractHtml = (string) $print->json('data.templates.contract.rendered_html');
        $ackHtml = (string) $print->json('data.templates.handover_receipt.rendered_html');

        $this->assertStringContainsString('البند السادس', $contractHtml);
        $this->assertStringContainsString($text, $contractHtml);
        $this->assertStringNotContainsString('111111', $contractHtml);
        $this->assertStringContainsString($text, $ackHtml);
        $this->assertStringNotContainsString('111111', $ackHtml);
    }

    public function test_update_vehicle_value_on_approved_contract_does_not_touch_vehicle(): void
    {
        [$customerId, $vehicleId] = $this->seedParty(400000);
        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'starts_at' => now()->addDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(2)->toIso8601String(),
            'include_vehicle_value_obligation' => true,
            'vehicle_value_text' => 'قيمة أولية',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/contracts/{$id}/approve")->assertOk();

        $this->postJson("/api/contracts/{$id}/vehicle-value", [
            'include_vehicle_value_obligation' => true,
            'vehicle_value_text' => 'قيمة السيارة وهو مبلغ وقدره 1000.000 فقط مليون جنيه',
        ])->assertOk()
            ->assertJsonPath('data.frozen_vehicle_value_text', 'قيمة السيارة وهو مبلغ وقدره 1000.000 فقط مليون جنيه')
            ->assertJsonPath('data.include_vehicle_value_obligation', true);

        $this->assertSame(400000, (int) Vehicle::query()->whereKey($vehicleId)->value('purchase_value_estimate'));

        $this->postJson("/api/contracts/{$id}/vehicle-value", [
            'include_vehicle_value_obligation' => false,
            'vehicle_value_text' => null,
        ])->assertOk()
            ->assertJsonPath('data.include_vehicle_value_obligation', false)
            ->assertJsonPath('data.frozen_vehicle_value_text', null);

        $ackHtml = (string) $this->getJson("/api/contracts/{$id}/print")->assertOk()
            ->json('data.templates.handover_receipt.rendered_html');
        $this->assertStringNotContainsString('ملزم بسداد ثمن السيارة', $ackHtml);
    }

    public function test_include_without_text_is_rejected(): void
    {
        [$customerId, $vehicleId] = $this->seedParty(null);

        $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customerId,
            'vehicle_id' => $vehicleId,
            'starts_at' => now()->addDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(2)->toIso8601String(),
            'include_vehicle_value_obligation' => true,
            'vehicle_value_text' => '   ',
        ])->assertStatus(422);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function seedParty(?int $purchaseValue): array
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل ثمن',
            'phone' => '010'.random_int(10000000, 99999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-'.random_int(1000, 9999),
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'VV-'.random_int(100, 999),
            'plate' => 'ق ي م '.random_int(10, 99),
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 800,
            'default_deposit' => 2000,
            'purchase_value_estimate' => $purchaseValue,
            'is_active' => true,
        ]);

        return [(int) $customer->id, (int) $vehicle->id];
    }
}
