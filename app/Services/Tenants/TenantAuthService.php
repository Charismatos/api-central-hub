<?php

namespace App\Services\Tenants;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TenantAuthService
{
    /**
     * What: Verifies inbound API request signatures using the tenant's API key and decrypted client secret.
     * Where: Security and authentication service layer.
     * When: Called during custom Sanctum token requests or webhook authentication checks.
     * Who: API Security Guard.
     * Why: Guarantees that tenants communicating with the central hub are cryptographically validated, 
     *       protecting shared backend server resources from unauthorized third-party requests.
     */
    public function validateCredentials(string $apiKey, string $rawSecret): ?Tenant
    {
        $tenant = Tenant::active()->where('api_key', $apiKey)->first();

        if (!$tenant) {
            return null;
        }

        // Fetch the decrypted client secret from the model accessor
        $storedSecret = $tenant->getDecryptedClientSecretAttribute();

        // Perform a secure timing-safe comparison to prevent timing attacks
        if (!hash_equals($storedSecret, $rawSecret)) {
            return null;
        }

        return $tenant;
    }
}