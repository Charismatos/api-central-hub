<?php

namespace App\Console\Commands\Tenants;

use App\Services\Tenants\TenantCredentialService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('tenants:create {--name= : Human-readable tenant name} {--slug= : Unique lowercase tenant slug} {--vertical= : Lowercase tenant industry or product category}')]
#[Description('Create a tenant and display its one-time API credentials')]
class CreateTenantCommand extends Command
{
    /**
     * What: Validates operator-supplied tenant details, creates the tenant, and displays its credentials once.
     * Where: Operator-only Artisan command in app/Console/Commands/Tenants.
     * When: Run manually by an authorized hub operator during tenant onboarding.
     * Who: A person or deployment process with permission to execute commands on the hub environment.
     * Why: Enables controlled credential issuance without exposing a public tenant-registration endpoint.
     */
    public function handle(TenantCredentialService $credentialService): int
    {
        // What: Validate only the tenant attributes allowed at onboarding.
        // Where: The command boundary, before any database write.
        // When: Every execution, including non-interactive deployments.
        // Who: Laravel's validator checks the operator-provided options.
        // Why: Prevents incomplete, malformed, or duplicate tenant identifiers from reaching persistence.
        $validator = Validator::make([
            'name' => $this->option('name'),
            'slug' => $this->option('slug'),
            'vertical' => $this->option('vertical'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                'unique:tenants,slug',
            ],
            'vertical' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
            ],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        // What: Persist a complete active tenant and fresh credentials through the domain service.
        // Where: Tenant onboarding service and tenants database table.
        // When: Only after all supplied attributes pass validation.
        // Who: TenantCredentialService generates the key and secret and encrypts the stored secret via the model.
        // Why: Keeps credential generation out of HTTP input and avoids creating incomplete tenant records.
        $credentials = $credentialService->createTenant($validator->validated());

        $this->info('Tenant created successfully.');
        $this->line('Tenant UUID: '.$credentials['tenant']->uuid);
        $this->newLine();
        $this->warn('Copy these credentials now. The client secret is shown only once.');
        $this->line('API_CENTRAL_HUB_API_KEY='.$credentials['api_key']);
        $this->line('API_CENTRAL_HUB_CLIENT_SECRET='.$credentials['client_secret']);

        return self::SUCCESS;
    }
}
