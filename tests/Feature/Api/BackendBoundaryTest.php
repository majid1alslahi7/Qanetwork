<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BackendBoundaryTest extends TestCase
{
    public function test_root_identifies_backend_without_rendering_a_web_interface(): void
    {
        $this->get('/')->assertOk()->assertExactJson(['service' => 'QaNetwork API', 'api_version' => 'v1']);
    }

    public function test_web_login_and_portal_routes_are_not_exposed(): void
    {
        foreach (['/login', '/portal', '/portal/data/seller/wallets', '/portal/data/admin/deposits'] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->postJson('/login', [])->assertNotFound();
        $this->postJson('/logout', [])->assertNotFound();
        $this->assertFalse(Route::has('portal.home'));
    }

    public function test_api_authentication_error_is_json_even_without_accept_header(): void
    {
        $this->get('/api/v1/seller/wallets')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }
}
