<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PublicLoginSourceTest extends TestCase
{
    public function test_login_page_has_no_embedded_demo_credential(): void
    {
        $path = dirname(__DIR__, 3).'/ui-pwa/src/pages/LoginPage.tsx';
        $src = file_get_contents($path);
        $this->assertIsString($src);
        $this->assertStringNotContainsString('const DEMO', $src);
        $this->assertStringNotContainsString('auth-demo', $src);
        $this->assertStringNotContainsString('IS_DEV', $src);
        $this->assertDoesNotMatchRegularExpression('/password\s*:\s*[\'"][^\'"]+[\'"]/', $src);
        $this->assertStringContainsString('import.meta.env.DEV', $src);
        $this->assertStringContainsString("VITE_ENABLE_DEMO_LOGIN !== 'true'", $src);
    }
}
