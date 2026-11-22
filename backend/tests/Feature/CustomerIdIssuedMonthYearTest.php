<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerIdIssuedMonthYearTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_store_accepts_year_month_and_persists_first_of_month(): void
    {
        $res = $this->postJson('/api/customers', [
            'full_name' => 'عميل شهر سنة',
            'phone' => '01099887766',
            'nationality' => 'مصري',
            'birth_date' => '1990-05-01',
            'license_number' => 'LIC-MY-1',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'national_id_issued_on' => '2019-07',
        ]);

        $res->assertCreated();
        $customer = Customer::query()->where('phone', '01099887766')->firstOrFail();
        $this->assertSame('2019-07-01', $customer->national_id_issued_on?->toDateString());
    }

    public function test_store_forces_day_to_first_when_full_date_sent(): void
    {
        $res = $this->postJson('/api/customers', [
            'full_name' => 'عميل يوم يُهمل',
            'phone' => '01099887755',
            'nationality' => 'مصري',
            'birth_date' => '1991-03-15',
            'license_number' => 'LIC-MY-2',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'national_id_issued_on' => '2020-11-28',
        ]);

        $res->assertCreated();
        $customer = Customer::query()->where('phone', '01099887755')->firstOrFail();
        $this->assertSame('2020-11-01', $customer->national_id_issued_on?->toDateString());
    }
}
