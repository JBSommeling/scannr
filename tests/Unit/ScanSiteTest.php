<?php

namespace Tests\Unit;

use App\Console\Commands\ScanSite;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ScanSiteTest extends TestCase
{
    private ScanSite $command;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new ScanSite();
        $this->reflection = new ReflectionClass($this->command);
    }

    private function invokeMethod(string $methodName, array $parameters = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        return $method->invokeArgs($this->command, $parameters);
    }

    // ==================
    // truncate tests
    // ==================

    public function test_truncate_returns_full_string_if_under_length(): void
    {
        $result = $this->invokeMethod('truncate', ['short', 10]);
        $this->assertEquals('short', $result);
    }

    public function test_truncate_returns_exact_length_string(): void
    {
        $result = $this->invokeMethod('truncate', ['exactly10!', 10]);
        $this->assertEquals('exactly10!', $result);
    }

    public function test_truncate_truncates_long_string_with_ellipsis(): void
    {
        $result = $this->invokeMethod('truncate', ['this is a very long string', 10]);
        $this->assertEquals('this is...', $result);
        $this->assertEquals(10, strlen($result));
    }

    public function test_truncate_handles_minimum_length(): void
    {
        $result = $this->invokeMethod('truncate', ['hello', 3]);
        $this->assertEquals('...', $result);
    }

    public function test_truncate_handles_empty_string(): void
    {
        $result = $this->invokeMethod('truncate', ['', 10]);
        $this->assertEquals('', $result);
    }
}
