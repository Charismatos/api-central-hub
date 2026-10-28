<?php

namespace Tests\Feature\Tenants;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateTenantCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What: Verifies the positive onboarding path creates a tenant and displays its one-time credentials.
     * Where: Feature test for tenants:create.
     * When: The Artisan command receives valid tenant details.
     * Who: PHPUnit runs the command through Laravel's console kernel and real test database.
     * Why: Confirms operators can provision a usable tenant without a public registration route.
     */
    public function test_positive_operator_onboarding_creates_tenant_and_displays_credentials(): void
    {
        $exitCode = Artisan::call('tenants:create', [
            '--name' => 'East Africa Retail',
            '--slug' => 'east-africa-retail',
            '--vertical' => 'ecommerce',
        ]);
        $output = Artisan::output();

        $tenant = Tenant::where('slug', 'east-africa-retail')->firstOrFail();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Tenant created successfully.', $output);
        $this->assertStringContainsString('API_CENTRAL_HUB_API_KEY='.$tenant->api_key, $output);
        $this->assertStringContainsString(
            'API_CENTRAL_HUB_CLIENT_SECRET='.$tenant->getDecryptedClientSecretAttribute(),
            $output,
        );
        $this->assertSame('active', $tenant->status);
        $this->assertMatchesRegularExpression('/\Athub_[A-Za-z0-9]{32}\z/', $tenant->api_key);
        $this->assertNotSame(
            $tenant->getDecryptedClientSecretAttribute(),
            DB::table('tenants')->where('id', $tenant->id)->value('client_secret_encrypted'),
        );
    }

    /**
     * What: Verifies the negative onboarding path rejects invalid operator input without creating a tenant.
     * Where: Feature test for the tenants:create command's validation boundary.
     * When: The required tenant slug has an invalid format.
     * Who: Laravel's validator and the Artisan command return a failure to the operator.
     * Why: Ensures invalid provisioning input cannot create a partial tenant or issue credentials.
     */
    public function test_negative_operator_onboarding_rejects_invalid_slug_without_creating_tenant(): void
    {
        $exitCode = Artisan::call('tenants:create', [
            '--name' => 'Invalid Tenant',
            '--slug' => 'Invalid_Tenant',
            '--vertical' => 'ecommerce',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The slug field format is invalid.', $output);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertStringNotContainsString('API_CENTRAL_HUB_CLIENT_SECRET=', $output);
    }

    /**
     * What: Verifies the edge case of an already-used tenant slug is rejected without modifying its owner.
     * Where: Feature test for tenant onboarding uniqueness.
     * When: An operator tries to provision a second tenant with an existing slug.
     * Who: Laravel's validator prevents the duplicate before TenantCredentialService writes credentials.
     * Why: Protects tenant identity and ensures rejected onboarding does not rotate another tenant's secrets.
     */
    public function test_edge_case_duplicate_slug_does_not_create_or_modify_a_tenant(): void
    {
        $existingTenant = Tenant::factory()->create([
            'name' => 'Existing Retail Tenant',
            'slug' => 'east-africa-retail',
            'api_key' => 'thub_existing_retail_key',
            'client_secret_encrypted' => 'existing-retail-secret',
        ]);
        $existingApiKey = $existingTenant->api_key;
        $existingSecret = $existingTenant->getDecryptedClientSecretAttribute();

        $exitCode = Artisan::call('tenants:create', [
            '--name' => 'Duplicate Retail Tenant',
            '--slug' => 'east-africa-retail',
            '--vertical' => 'ecommerce',
        ]);
        $output = Artisan::output();

        $existingTenant->refresh();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('The slug has already been taken.', $output);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertSame($existingApiKey, $existingTenant->api_key);
        $this->assertSame($existingSecret, $existingTenant->getDecryptedClientSecretAttribute());
        $this->assertStringNotContainsString('API_CENTRAL_HUB_CLIENT_SECRET=', $output);
    }
}
