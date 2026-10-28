<?php

namespace Tests\Feature\Tenants;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantMultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What: Tests that tenant client secrets are automatically encrypted in the database.
     * Where: PHPUnit Feature test class (`tests/Feature/TenantMultiTenancyTest.php`).
     * When: Executed during `php artisan test`.
     * Who: PHPUnit Test Runner.
     * Why: Ensures encryption-at-rest is enforced at the database persistence level.
     */
    public function test_tenant_client_secrets_are_encrypted_in_database(): void
    {
        $tenant = Tenant::create([
            'uuid' => Str::uuid(),
            'name' => 'Kampala Fintech Ltd',
            'slug' => 'kampala-fintech',
            'vertical' => 'fintech',
            'api_key' => 'thub_test_key_12345',
            'client_secret_encrypted' => 'super-secret-password-raw',
            'status' => 'active',
        ]);

        $rawRecord = DB::table('tenants')->where('id', $tenant->id)->first();

        $this->assertNotEquals('super-secret-password-raw', $rawRecord->client_secret_encrypted);
        $this->assertEquals('super-secret-password-raw', $tenant->getDecryptedClientSecretAttribute());
    }

    /**
     * What: Tests that a valid tenant can authenticate and receive a Sanctum token.
     * Where: PHPUnit Feature test class.
     * When: Executed during API integration testing.
     * Who: PHPUnit Test Runner.
     * Why: Verifies the OAuth token endpoint correctly validates credentials and issues tokens.
     */
    public function test_valid_tenant_can_authenticate_and_receive_sanctum_token(): void
    {
        $rawSecret = 'my-secure-fintech-secret';
        Tenant::create([
            'uuid' => Str::uuid(),
            'name' => 'Kampala Fintech Ltd',
            'slug' => 'kampala-fintech',
            'vertical' => 'fintech',
            'api_key' => 'thub_test_key_abc',
            'client_secret_encrypted' => $rawSecret,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/oauth/token', [
            'api_key' => 'thub_test_key_abc',
            'client_secret' => $rawSecret,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'tenant' => ['id', 'name', 'vertical', 'api_key'],
                'access_token',
                'token_type',
            ]);
    }

    /**
     * What: Tests that the middleware blocks requests with missing or invalid tenant API keys.
     * Where: PHPUnit Feature test class.
     * When: Executed during security middleware validation.
     * Who: PHPUnit Test Runner.
     * Why: Prevents unauthorized traffic from accessing protected tenant hub routes.
     */
    public function test_middleware_blocks_requests_with_missing_tenant_api_key(): void
    {
        $response = $this->getJson('/api/v1/tenant/protected-resource');

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Unauthorized',
                'message' => 'Missing tenant identification header (X-Tenant-Key).',
            ]);
    }

    /**
     * What: Tests that suspended or inactive tenants cannot access hub middleware.
     * Where: PHPUnit Feature test class.
     * When: Executed during multi-tenant lifecycle validation.
     * Who: PHPUnit Test Runner.
     * Why: Enforces tenant suspension states to lock out inactive or blocked clients.
     */
    public function test_suspended_tenants_cannot_access_hub_middleware(): void
    {
        Tenant::create([
            'uuid' => Str::uuid(),
            'name' => 'Suspended ERP Corp',
            'slug' => 'suspended-erp',
            'vertical' => 'erp',
            'api_key' => 'thub_suspended_key',
            'client_secret_encrypted' => 'some-secret',
            'status' => 'suspended',
        ]);

        $response = $this->getJson('/api/v1/tenant/protected-resource', [
            'X-Tenant-Key' => 'thub_suspended_key',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Forbidden',
                'message' => 'Invalid or suspended tenant credential.',
            ]);
    }
}