<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ApiHealthCheckTest extends TestCase
{
    public function test_the_api_base_url_answers_for_app_health_checks(): void
    {
        $this->getJson('/api/v1')
            ->assertSuccessful()
            ->assertJsonPath('version', 'v1');
    }

    public function test_the_api_base_url_answers_head_checks(): void
    {
        $this->call('HEAD', '/api/v1')
            ->assertSuccessful();
    }
}
