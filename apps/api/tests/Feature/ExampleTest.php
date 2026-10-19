<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_api_root_returns_a_service_status(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertExactJson(['service' => 'metricove-api', 'status' => 'ok']);
    }
}
