<?php

namespace App\Http\Resources\Tenants;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    /**
     * What: Transforms the Tenant model into a standardized JSON API response structure.
     * Where: HTTP presentation/serialization layer.
     * When: Just before returning JSON responses to clients or tenant dashboards.
     * Who: API Response Serializer.
     * Why: Explicitly filters out sensitive internal fields (like `client_secret_encrypted`) while exposing safe public attributes (`uuid`, `name`, `vertical`, `status`).
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'vertical' => $this->vertical,
            'api_key' => $this->api_key,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}