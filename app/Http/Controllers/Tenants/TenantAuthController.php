<?php

namespace App\Http\Controllers\Tenants;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenants\TenantResource;
use App\Services\Tenants\TenantAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantAuthController extends Controller
{
    protected TenantAuthService $authService;

    /**
     * What: Injects the TenantAuthService dependency into the controller.
     * Where: Controller constructor injection.
     * When: Instantiated automatically by the Laravel service container when the auth route is hit.
     * Who: Framework Dependency Injection Container.
     * Why: Adheres to SOLID design principles by decoupling authentication logic from the HTTP layer.
     */
    public function __construct(TenantAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * What: Authenticates a tenant server and issues a secure Laravel Sanctum token for subsequent API calls.
     * Where: API Controller endpoint (`POST /api/v1/oauth/token`).
     * When: When a tenant application boots up or needs to refresh its communication session with the hub.
     * Who: Tenant Backend Server / Client Application.
     * Why: Provides secure, token-based authorization backed by Laravel Sanctum, ensuring performance under Octane.
     */
    public function authenticate(Request $request): JsonResponse
    {
        $request->validate([
            'api_key' => 'required|string',
            'client_secret' => 'required|string',
        ]);

        $tenant = $this->authService->validateCredentials(
            $request->input('api_key'),
            $request->input('client_secret')
        );

        if (!$tenant) {
            return response()->json([
                'message' => 'Invalid API key or client secret credentials provided.'
            ], 401);
        }

        // Issue a Sanctum API token for the tenant entity
        $token = $tenant->createToken('tenant-hub-session')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'tenant' => new TenantResource($tenant),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
}