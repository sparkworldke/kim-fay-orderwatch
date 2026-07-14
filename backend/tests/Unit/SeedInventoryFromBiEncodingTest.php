<?php

namespace Tests\Unit;

use App\Console\Commands\SeedInventoryFromBi;
use ReflectionMethod;
use Tests\TestCase;

class SeedInventoryFromBiEncodingTest extends TestCase
{
    public function test_normalize_text_encoding_replaces_windows_1252_micro_sign(): void
    {
        $command = new SeedInventoryFromBi;
        $method = new ReflectionMethod(SeedInventoryFromBi::class, 'normalizeTextEncoding');
        $method->setAccessible(true);

        $raw = 'Fay Cling Film Catering 30cmx1500M 12' . "\xB5" . ' MP';
        $normalized = $method->invoke($command, $raw);

        $this->assertStringNotContainsString("\xB5", $normalized);
        $this->assertStringContainsString('12u MP', $normalized);
        $this->assertTrue(mb_check_encoding($normalized, 'UTF-8'));
    }
}