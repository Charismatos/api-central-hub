<?php

namespace App\Services\Tenants;

use App\Models\Tenant;
use LogicException;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenantId(): int|string|null
    {
        return $this->tenant?->getKey();
    }

    public function requireTenantId(): int|string
    {
        return $this->tenantId()
            ?? throw new LogicException('A tenant context is required for tenant-owned data.');
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
