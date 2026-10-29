<?php

namespace App\Http\Middleware\Tenants;

use App\Models\Tenant;
use App\Services\Tenants\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantTokenMatches
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantContext->tenant();
        $authenticatedPrincipal = $request->user();

        if (! $tenant || ! $authenticatedPrincipal instanceof Tenant || ! $authenticatedPrincipal->is($tenant)) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'The authenticated token does not belong to the identified tenant.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
