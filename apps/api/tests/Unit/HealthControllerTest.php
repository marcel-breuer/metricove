<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\HealthController;
use PHPUnit\Framework\TestCase;

class HealthControllerTest extends TestCase
{
    public function test_it_returns_a_successful_health_payload(): void
    {
        $response = (new HealthController)();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'ok'], $response->getData(true));
    }
}
