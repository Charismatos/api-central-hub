<?php

namespace App\Services\Tenants;

use App\Models\Tenant;
use Illuminate\Support\Str;

class TenantCredentialService
{
    /**
     * What: Generates secure API credentials (API Key and Secret) for a newly onboarded tenant.
     * Where: Business logic service layer.
     * When: Called during tenant registration/onboarding controllers.
     * Who: System Backend Service.
     * Why: Centralizes credential generation logic, ensuring cryptographic entropy and uniqueness across all tenants (Fintech, SaaS, etc.).
     */
    public function generateCredentials(Tenant $tenant): array
    {
        $apiKey = 'thub_' . Str::random(32); // Public identifier prefixing the hub domain namespace.
        $rawSecret = Str::random(64); // High-entropy secret token given once to the tenant.

        $tenant->update([
            'api_key' => $apiKey,
            'client_secret_encrypted' => $rawSecret, // Triggers the model mutator for encryption-at-rest.
        ]);

        return [
            'api_key' => $apiKey,
            'client_secret' => $rawSecret, // Returned ONLY once upon creation; never stored in plaintext.
        ];
    }
}