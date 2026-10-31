<?php

namespace Tests\Feature\Tenants;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantHybridApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test tenant with a valid API key
        $this->tenant = Tenant::create([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'name' => 'Kampala Logistics Hub',
            'slug' => 'kampala-logistics',
            'category' => 'ERP & Surveillance',
            'api_key' => 'thub_test_api_key_12345',
            'client_secret_encrypted' => 'secret',
            'status' => 'active',
        ]);
    }

    /**
     * Test Group 2: Android / Edge Client payload storage and retrieval
     * authenticated purely via the X-Tenant-Key header.
     */
    public function test_group_two_device_payload_endpoints_succeed_with_tenant_key_header(): void
    {
        // 1. Test POST ingestion (Simulating Android WebRTC / telemetry publisher)
        $storeResponse = $this->withHeader('X-Tenant-Key', $this->tenant->api_key)
            ->postJson('/api/v1/tenant/payload?payload_type=webrtc-offer', [
                'webrtc' => [
                    'type' => 'offer',
                    'sdp' => 'v=0\no=- 123456 2 IN IP4 127.0.0.1...',
                ]
            ]);

        $storeResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'payload_type' => 'webrtc-offer',
            ]);

        // 2. Test GET retrieval (Simulating Laravel Consumer App)
        $retrieveResponse = $this->withHeader('X-Tenant-Key', $this->tenant->api_key)
            ->getJson('/api/v1/tenant/payload?payload_type=webrtc-offer');

        $retrieveResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ])
            ->assertJsonPath('payload.body.webrtc.type', 'offer');
    }

    /**
     * Test Group 1: Backend server protected resource requiring Sanctum Bearer token
     * and matching tenant context validation.
     */
    public function test_group_one_protected_resource_succeeds_with_sanctum_token_and_tenant_key(): void
    {
        // Issue a Sanctum token for the tenant entity
        $token = $this->tenant->createToken('tenant-hub-session')->plainTextToken;

        // Act as the authenticated tenant principal using Sanctum and pass X-Tenant-Key header
        $response = $this->withToken($token)
            ->withHeader('X-Tenant-Key', $this->tenant->api_key)
            ->getJson('/api/v1/tenant/protected-resource');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'tenant' => [
                    'uuid' => $this->tenant->uuid,
                    'name' => 'Kampala Logistics Hub',
                ],
            ]);
    }

    /**
     * Test Security Boundary: Group 1 blocks requests lacking a Sanctum token.
     */
    public function test_group_one_protected_resource_rejects_requests_missing_sanctum_token(): void
    {
        // Sending only X-Tenant-Key without Bearer token should fail Group 1 auth:sanctum check
        $response = $this->withHeader('X-Tenant-Key', $this->tenant->api_key)
            ->getJson('/api/v1/tenant/protected-resource');

        $response->assertStatus(401);
    }
}
