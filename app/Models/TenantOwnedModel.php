<?php

namespace App\Models;

use App\Services\Tenants\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class TenantOwnedModel extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->tenantId();

            if ($tenantId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $tenantId);
        });

        static::saving(function (self $model): void {
            $tenantId = app(TenantContext::class)->tenantId();

            if ($tenantId === null) {
                throw new LogicException('A tenant context is required to save tenant-owned data.');
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    public function getConnectionName(): ?string
    {
        $tenant = app(TenantContext::class)->tenant();

        if (! $tenant || $tenant->connection_config === null) {
            return config('database.default');
        }

        return 'tenant_data';
    }
}
