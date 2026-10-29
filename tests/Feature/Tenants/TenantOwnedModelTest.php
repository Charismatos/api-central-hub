<?php

namespace Tests\Feature\Tenants;

use App\Models\Tenant;
use App\Models\TenantOwnedModel;
use App\Services\Tenants\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class TenantOwnedModelTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantTable(): void
    {
        Schema::create('tenant_owned_test_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('name');
        });
    }

    public function test_tenant_owned_records_are_scoped_and_assigned_to_current_tenant(): void
    {
        $this->createTenantTable();

        $firstTenant = Tenant::factory()->create();
        $secondTenant = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $context->set($firstTenant);
        TenantOwnedTestRecord::create(['name' => 'first']);

        $context->set($secondTenant);
        TenantOwnedTestRecord::create(['name' => 'second']);

        $this->assertSame(['second'], TenantOwnedTestRecord::query()->pluck('name')->all());
        $this->assertDatabaseHas('tenant_owned_test_records', [
            'tenant_id' => $firstTenant->id,
            'name' => 'first',
        ]);
        $this->assertDatabaseHas('tenant_owned_test_records', [
            'tenant_id' => $secondTenant->id,
            'name' => 'second',
        ]);
    }

    public function test_tenant_owned_records_are_unavailable_without_a_tenant_context(): void
    {
        $this->createTenantTable();

        $this->assertSame([], TenantOwnedTestRecord::query()->pluck('name')->all());

        $this->expectException(LogicException::class);

        TenantOwnedTestRecord::create(['name' => 'unscoped']);
    }
}

class TenantOwnedTestRecord extends TenantOwnedModel
{
    protected $table = 'tenant_owned_test_records';

    protected $fillable = ['name'];

    public $timestamps = false;
}
