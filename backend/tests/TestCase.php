<?php

namespace Tests;

use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** معظم سيناريوهات العقود تفترض رخصة تسيير مكتملة تلقائياً في الاختبار. */
    protected bool $autoCompleteVehicleLicense = true;

    protected function setUp(): void
    {
        parent::setUp();

        Vehicle::created(function (Vehicle $vehicle) {
            if (! $this->autoCompleteVehicleLicense) {
                return;
            }
            if ($vehicle->hasCompleteLicense()) {
                return;
            }
            VehicleDocument::query()->create([
                'vehicle_id' => $vehicle->id,
                'type' => 'رخصة تسيير',
                'file_path' => 'tests/license-front.jpg',
                'expires_on' => now()->addYears(2)->toDateString(),
                'issued_on' => now()->toDateString(),
            ]);
        });
    }
}
