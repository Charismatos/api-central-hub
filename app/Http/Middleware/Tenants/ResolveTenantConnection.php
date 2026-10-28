<?php

namespace App\Http\Middleware\Tenants;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantConnection
{
    /**
     * What: Intercepts inbound HTTP requests, identifies the target tenant via headers or subdomains, 
     *       and dynamically configures database connections or context.
     * Where: HTTP middleware request pipeline.
     * When: Executed on every tenant-scoped API request before reaching controllers.
     * Who: HTTP Kernel / Request Router.
     * Why: Enables cost-efficient multi-tenancy sharing the central hub database, while providing a seamless 
     *       transition path to inject isolated external DB credentials (via `connection_config`) when a tenant migrates.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Extract the tenant API key or slug from inbound request headers (e.g., X-Tenant-Key)
        $apiKey = $request->header('X-Tenant-Key');

        if (!$apiKey) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Missing tenant identification header (X-Tenant-Key).'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Retrieve the active tenant record from the central database hub
        $tenant = Tenant::active()->where('api_key', $apiKey)->first();

        if (!$tenant) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Invalid or suspended tenant credential.'
            ], Response::HTTP_FORBIDDEN);
        }

        // Bind the resolved tenant instance into the service container for global application access
        app()->instance(Tenant::class, $tenant);

        // Check if this specific tenant has outgrown the hub and requires an isolated migrated database host
        if (!empty($tenant->connection_config)) {
            $this->configureIsolatedDatabase($tenant->connection_config);
        }

        return $next($request);
    }

    /**
     * What: Dynamically overrides Laravel's database configuration at runtime for migrated tenants.
     * Where: Internal middleware helper method.
     * When: Triggered if a tenant's `connection_config` JSON payload is populated.
     * Who: Tenant Connection Resolver.
     * Why: Allows high-scale tenants (e.g., large ERP or Fintech clients) to run on their own dedicated servers 
     *       without changing application business logic or controller code.
     */
    protected function configureIsolatedDatabase(array $config): void
    {
        // Dynamically set runtime connection parameters for the 'tenant' database connection driver
        Config::set('database.connections.tenant_isolated', [
            'driver' => $config['driver'] ?? 'mysql',
            'host' => $config['host'],
            'port' => $config['port'] ?? '3306',
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);

        // Purge and reconnect to ensure subsequent database queries point to the tenant's isolated server
        DB::purge('tenant_isolated');
        DB::setDefaultConnection('tenant_isolated');
    }
}