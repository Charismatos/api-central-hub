<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * What: Executes the schema creation for the centralized tenant database table.
     * Where: Central Database (PostgreSQL/MySQL hub instance).
     * When: During initial platform deployment or tenant module setup.
     * Who: System Architect / Deployment Script via CLI.
     * Why: Establishes the foundational table to securely store tenant credentials, states, and metadata.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id(); // Primary surrogate key for internal database relations.
            $table->uuid('uuid')->unique(); // Unique public identifier to prevent ID enumeration attacks in APIs.
            $table->string('name'); // Human-readable business name (e.g., "Kampala Fintech Ltd").
            $table->string('slug')->unique(); // URL-safe identifier used for routing and subdomain resolution.
            $table->string('vertical'); // Industry categorization (e.g., e-commerce, erp, saas, fintech).
            
            // Credential & Security Columns
            $table->text('api_key')->unique(); // Hashed or public-facing API client identifier.
            $table->text('client_secret_encrypted'); // Cryptographically encrypted secret token for secure tenant server-to-hub communication.
            $table->json('connection_config')->nullable(); // Stores isolated DB credentials for tenants that eventually migrate to their own hosts.
            
            $table->string('status')->default('active'); // Lifecycle state: active, suspended, migrating, archived.
            $table->timestamps(); // Audit trail for record creation and updates.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};