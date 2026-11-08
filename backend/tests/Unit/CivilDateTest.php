<?php

namespace Tests\Unit;

use App\Support\CivilDate;
use Tests\TestCase;

class CivilDateTest extends TestCase
{
    public function test_cairo_winter_summer_bare_and_null(): void
    {
        config(['app.timezone' => 'Africa/Cairo']);

        $this->assertSame('2026-11-01', CivilDate::toDateString('2026-10-31T22:00:00.000000Z'));
        $this->assertSame('2027-09-08', CivilDate::toDateString('2027-09-07T21:00:00.000000Z'));
        $this->assertSame('2024-01-01', CivilDate::toDateString('2023-12-31T22:00:00.000000Z'));
        $this->assertSame('2026-11-01', CivilDate::toDateString('2026-11-01T00:00:00+02:00'));
        $this->assertSame('2026-07-02', CivilDate::toDateString('2026-07-01T21:30:00Z'));
        $this->assertSame('2026-11-01', CivilDate::toDateString('2026-11-01'));
        $this->assertSame('2026-11-01', CivilDate::toDateString('2026-11-01 00:00:00'));
        $this->assertSame('', CivilDate::toDateString(null, ''));
        $this->assertSame([2026, 11], CivilDate::yearAndMonth('2026-10-31T22:00:00.000000Z'));
    }
}
