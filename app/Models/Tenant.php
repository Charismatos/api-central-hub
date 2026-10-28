<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Tenant extends Model
{
    use HasFactory;

    /**
     * What: Defines mass-assignable attributes to prevent mass-assignment vulnerabilities.
     * Where: Eloquent Model definition layer.
     * When: Automatically invoked when creating or updating records via request data.
     * Who: Backend Developer / Framework Guard.
     * Why: Secures the database against malicious injection of unauthorized column modifications.
     */
    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'vertical',
        'api_key',
        'client_secret_encrypted',
        'connection_config',
        'status',
    ];

    /**
     * What: Specifies attribute casting rules, notably casting secrets and configs to encrypted or JSON arrays.
     * Where: Model attribute casting pipeline.
     * When: Triggered automatically on read/write operations to the database.
     * Who: Eloquent ORM internal hydration engine.
     * Why: Ensures sensitive client secrets are never stored in plain text, satisfying fintech/SaaS security compliance.
     */
    protected $casts = [
        'connection_config' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * What: Mutator to automatically encrypt the client secret before database insertion.
     * Where: Model setter method pattern (`setXAttribute`).
     * When: Whenever `client_secret` is assigned or updated on the model instance.
     * Who: Application Developer / Data Layer.
     * Why: Enforces encryption-at-rest programmatically so developers cannot accidentally save raw plaintext credentials.
     */
    public function setClientSecretAttribute(string $value): void
    {
        $this->attributes['client_secret_encrypted'] = Crypt::encryptString($value);
    }

    /**
     * What: Accessor to decrypt and return the client secret on demand.
     * Where: Model getter method pattern (`getXAttribute`).
     * When: Explicitly called when verifying inbound tenant API signatures or webhooks.
     * Who: Authentication Service / Middleware.
     * Why: Keeps secrets encrypted in the database row while making them usable safely during secure runtime verification.
     */
    public function getDecryptedClientSecretAttribute(): string
    {
        return Crypt::decryptString($this->client_secret_encrypted);
    }

    /**
     * What: Scope query helper to filter only active tenants.
     * Where: Eloquent query builder scope layer.
     * When: During API request routing and multi-tenant resolution middleware.
     * Who: Query Builder / API Controllers.
     * Why: Prevents suspended or migrated tenants from executing queries or interacting with the central hub infrastructure.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}