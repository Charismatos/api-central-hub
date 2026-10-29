<?php

namespace App\Console\Commands\Tenants;

use App\Models\TenantDomain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tenants:domain:verify {domain : Registered tenant domain}')]
#[Description('Verify tenant domain ownership against its DNS TXT record')]
class VerifyTenantDomainCommand extends Command
{
    public function handle(): int
    {
        $domain = mb_strtolower(rtrim($this->argument('domain'), '.'));
        $tenantDomain = TenantDomain::where('domain', $domain)
            ->whereNull('verified_at')
            ->first();

        if (! $tenantDomain || ! $tenantDomain->verification_token_hash) {
            $this->error('No pending DNS verification exists for that domain.');

            return self::FAILURE;
        }

        $recordName = '_api-central-hub-verification.'.$domain;

        if (! function_exists('dns_get_record')) {
            $this->error('DNS TXT lookup is unavailable in this PHP runtime.');

            return self::FAILURE;
        }

        $records = dns_get_record($recordName, DNS_TXT);

        if ($records === false) {
            $this->error('DNS lookup failed. Check resolver availability and retry.');

            return self::FAILURE;
        }

        foreach ($records as $record) {
            $value = $record['txt'] ?? implode('', $record['entries'] ?? []);

            if (hash_equals($tenantDomain->verification_token_hash, hash('sha256', $value))) {
                $tenantDomain->forceFill([
                    'verified_at' => now(),
                    'verification_token_hash' => null,
                ])->save();

                $this->info('Tenant domain verified and ready for domain-based routing.');

                return self::SUCCESS;
            }
        }

        $this->error('The required TXT verification record was not found or did not match.');

        return self::FAILURE;
    }
}
