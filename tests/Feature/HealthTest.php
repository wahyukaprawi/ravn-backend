<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok_without_internal_details(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $response->assertExactJson(['status' => 'ok']);
    }
}
