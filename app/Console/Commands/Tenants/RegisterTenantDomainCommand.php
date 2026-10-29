<?php

namespace App\Console\Commands\Tenants;

use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('tenants:domain:register {tenant_slug : Existing tenant slug} {domain : Custom domain to verify}')]
#[Description('Register a tenant domain and display its DNS verification record')]
class RegisterTenantDomainCommand extends Command
{
    public function handle(): int
    {
        $validated = Validator::make([
            'tenant_slug' => $this->argument('tenant_slug'),
            'domain' => mb_strtolower(rtrim($this->argument('domain'), '.')),
        ], [
            'tenant_slug' => ['required', 'string', 'exists:tenants,slug'],
            'domain' => [
                'required',
                'string',
                'max:253',
                'regex:/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/i',
                'unique:tenant_domains,domain',
            ],
        ])->validate();

        $tenant = Tenant::where('slug', $validated['tenant_slug'])->firstOrFail();
        $verificationToken = Str::random(48);

        $tenant->domains()->create([
            'domain' => $validated['domain'],
            'verification_token_hash' => hash('sha256', $verificationToken),
        ]);

        $this->info('Tenant domain registered and awaiting DNS verification.');
        $this->line('Create a TXT record at _api-central-hub-verification.'.$validated['domain']);
        $this->line('TXT value: '.$verificationToken);
        $this->warn('The domain will not route to the tenant until DNS verification succeeds.');

        return self::SUCCESS;
    }
}
