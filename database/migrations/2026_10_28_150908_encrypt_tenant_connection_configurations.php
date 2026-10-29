<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->text('connection_config')->nullable()->change();
        });

        DB::table('tenants')
            ->whereNotNull('connection_config')
            ->orderBy('id')
            ->chunkById(100, function ($tenants): void {
                foreach ($tenants as $tenant) {
                    $configuration = json_decode($tenant->connection_config, true, 512, JSON_THROW_ON_ERROR);
                    $json = json_encode($configuration, JSON_THROW_ON_ERROR);

                    DB::table('tenants')
                        ->where('id', $tenant->id)
                        ->update(['connection_config' => Crypt::encryptString($json)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tenants')
            ->whereNotNull('connection_config')
            ->orderBy('id')
            ->chunkById(100, function ($tenants): void {
                foreach ($tenants as $tenant) {
                    $configuration = Crypt::decryptString($tenant->connection_config);

                    DB::table('tenants')
                        ->where('id', $tenant->id)
                        ->update(['connection_config' => $configuration]);
                }
            });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->json('connection_config')->nullable()->change();
        });
    }
};
