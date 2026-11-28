<?php

namespace Tests\Unit;

use App\Support\SmartSearch;
use PHPUnit\Framework\TestCase;

class SmartSearchTest extends TestCase
{
    public function test_normalize_folds_hamza_and_teh_marbuta(): void
    {
        $this->assertSame('احمد', SmartSearch::normalize('أحمد'));
        $this->assertSame('ابراهيم', SmartSearch::normalize('إبراهيم'));
        $this->assertSame('فاطمه', SmartSearch::normalize('فاطمة'));
        $this->assertSame('علي', SmartSearch::normalize('على'));
    }

    public function test_compact_strips_spaces_for_plate_letters(): void
    {
        $this->assertSame('ابج1234', SmartSearch::compact('أ ب ج 1234'));
        $this->assertSame('ابج', SmartSearch::compact('ا ب ج'));
        $this->assertSame('ابج', SmartSearch::compact('أبج'));
        $this->assertTrue(str_contains(SmartSearch::compact('أ ب ج 1234'), SmartSearch::compact('ابج')));
    }
}
