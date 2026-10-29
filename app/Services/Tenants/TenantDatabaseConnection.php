<?php

namespace App\Services\Tenants;

use App\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TenantDatabaseConnection
{
    private const CONNECTION_NAME = 'tenant_data';

    /**
     * Configure an explicit tenant-data connection without changing Laravel's central default.
     *
     * @return array<string, mixed>|null Previous connection configuration for request cleanup.
     */
    public function activate(Tenant $tenant): ?array
    {
        $previousConfiguration = config('database.connections.'.self::CONNECTION_NAME);
        $configuration = $tenant->connection_config;

        if ($configuration === null) {
            $default = config('database.default');
            $configuration = config("database.connections.{$default}");
        } else {
            $configuration = $this->isolatedConfiguration($configuration);
        }

        if (! is_array($configuration)) {
            throw new InvalidArgumentException('Tenant database connection configuration is invalid.');
        }

        DB::purge(self::CONNECTION_NAME);
        Config::set('database.connections.'.self::CONNECTION_NAME, $configuration);

        return $previousConfiguration;
    }

    public function restore(?array $previousConfiguration): void
    {
        DB::purge(self::CONNECTION_NAME);
        Config::set('database.connections.'.self::CONNECTION_NAME, $previousConfiguration);
    }

    /**
     * Merge only recognized connection options onto Laravel's driver defaults.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function isolatedConfiguration(array $configuration): array
    {
        $driver = $configuration['driver'] ?? null;

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            throw new InvalidArgumentException('Tenant database driver must be mysql, mariadb, or pgsql.');
        }

        $defaults = config("database.connections.{$driver}");

        if (! is_array($defaults)) {
            throw new InvalidArgumentException("The [{$driver}] database driver is not configured.");
        }

        foreach (['host', 'database', 'username'] as $required) {
            if (! is_string($configuration[$required] ?? null) || $configuration[$required] === '') {
                throw new InvalidArgumentException("Tenant database [{$required}] is required.");
            }
        }

        $allowedOptions = array_fill_keys(array_keys($defaults), true);

        return array_replace($defaults, Arr::only($configuration, array_keys($allowedOptions)));
    }
}
