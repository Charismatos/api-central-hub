<?php

use App\Http\Controllers\Tenants\TenantAuthController;
use App\Http\Middleware\Tenants\ResolveTenantConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| What: Defines the URL routing table for inbound API requests targeting the central hub.
| Where: Laravel routing configuration (`routes/api.php`).
| When: Loaded automatically by the RouteServiceProvider during application boot.
| Who: Laravel Router / HTTP Inbound Dispatcher.
| Why: Maps URI endpoints to appropriate controllers and applies layered security and multi-tenancy middleware.
*/

// Public Tenant Authentication Route (Used by tenants to obtain their initial Sanctum token)
Route::prefix('v1')->group(function () {
    Route::post('/oauth/token', [TenantAuthController::class, 'authenticate']);
});

// Protected Tenant-Scoped Routes
Route::prefix('v1/tenant')->middleware([
    ResolveTenantConnection::class, // Custom middleware resolving tenant and dynamic database connections
    'auth:sanctum',                  // Laravel Sanctum token guard ensuring authenticated server requests
])->group(function () {

    /**
     * What: Example protected resource endpoint accessible only to valid, active tenants.
     * Where: API route definition group.
     * When: Hit by external tenant applications (E-Commerce, ERP, SaaS, Fintech).
     * Who: Authenticated Tenant Servers.
     * Why: Provides a secure sandbox to execute tenant-specific business logic under the hub architecture.
     */
    Route::get('/protected-resource', function (Request $request) {
        // Retrieve the bound tenant instance from the service container (injected by middleware)
        $tenant = app(\App\Models\Tenant::class);

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully accessed central hub protected resource.',
            'tenant' => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'vertical' => $tenant->vertical,
            ]
        ]);
    });
});