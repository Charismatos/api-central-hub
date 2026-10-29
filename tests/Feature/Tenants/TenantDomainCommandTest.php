<?php

namespace Tests\Feature\Tenants;

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TenantDomainCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_registration_stores_only_a_hash_until_dns_is_verified(): void
    {
        Tenant::factory()->create(['slug' => 'example-shop']);

        $exitCode = Artisan::call('tenants:domain:register', [
            'tenant_slug' => 'example-shop',
            'domain' => 'Shop.Example.com.',
        ]);
        $output = Artisan::output();
        $tenantDomain = TenantDomain::where('domain', 'shop.example.com')->firstOrFail();
        preg_match('/TXT value: ([A-Za-z0-9]+)/', $output, $matches);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('_api-central-hub-verification.shop.example.com', $output);
        $this->assertNotEmpty($matches[1] ?? null);
        $this->assertSame(hash('sha256', $matches[1]), $tenantDomain->verification_token_hash);
        $this->assertNull($tenantDomain->verified_at);
    }
}
