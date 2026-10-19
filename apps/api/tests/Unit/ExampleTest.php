<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_php_runtime_meets_the_application_minimum(): void
    {
        $this->assertGreaterThanOrEqual(80300, PHP_VERSION_ID);
    }
}
