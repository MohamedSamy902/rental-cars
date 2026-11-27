<?php

namespace Tests\Unit;

use App\Rules\EgyptianPhone;
use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneInternationalTest extends TestCase
{
    #[DataProvider('validPhones')]
    public function test_accepts_egypt_and_international(string $phone): void
    {
        $failed = false;
        (new EgyptianPhone)->validate('phone', $phone, function () use (&$failed) {
            $failed = true;
        });
        $this->assertFalse($failed, "should accept {$phone}");
    }

    #[DataProvider('invalidPhones')]
    public function test_rejects_garbage(string $phone): void
    {
        $failed = false;
        (new EgyptianPhone)->validate('phone', $phone, function () use (&$failed) {
            $failed = true;
        });
        $this->assertTrue($failed, "should reject {$phone}");
    }

    public function test_canonicalize_egypt_and_intl(): void
    {
        $this->assertSame('01001112233', Phone::canonicalizeForStorage('01001112233'));
        $this->assertSame('01001112233', Phone::canonicalizeForStorage('+201001112233'));
        $this->assertSame('+966501234567', Phone::canonicalizeForStorage('+966501234567'));
        $this->assertSame('+491701234567', Phone::canonicalizeForStorage('00491701234567'));
    }

    /** @return list<array{0: string}> */
    public static function validPhones(): array
    {
        return [
            ['01001112233'],
            ['+201001112233'],
            ['00201001112233'],
            ['+966501234567'],
            ['00966501234567'],
            ['+971501234567'],
            ['+447911123456'],
            ['+12025550123'],
        ];
    }

    /** @return list<array{0: string}> */
    public static function invalidPhones(): array
    {
        return [
            ['123'],
            ['abcdef'],
            ['012345'],
        ];
    }
}
