<?php

namespace Tests\Feature;

use Tests\TestCase;

class RouteTest extends TestCase
{
    public function test_api_me_returns_401_without_token(): void
    {
        $response = $this->getJson('/api/auth/me');
        // Dump response for diagnosis
        if ($response->getStatusCode() === 404) {
            dump($response->getContent());
        }
        $response->assertStatus(401);
    }
}
