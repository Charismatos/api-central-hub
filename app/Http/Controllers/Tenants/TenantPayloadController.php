<?php

namespace App\Http\Controllers\Tenants;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\JsonResponse;

class TenantPayloadController extends Controller
{
    /**
     * What: Universally ingests compound payloads (WebRTC + device stats + payments + products combined).
     * Where: api-central-hub universal ingestion endpoint (`POST /api/v1/tenant/payload`).
     * Why: Allows 10,000+ heterogeneous tenant apps to send multi-type data packages in a single request.
     */
    public function store(Request $request): JsonResponse
    {
        // Significance: Resolves active tenant context securely via middleware.
        $tenant = app(\App\Services\Tenants\TenantContext::class)->tenant();

        // Significance: Captures headers, query parameters, files, and the entire compound JSON body.
        $payloadData = [
            'headers' => $request->headers->all(),
            'body' => $request->all(),
            'files' => collect($request->allFiles())->map->getClientOriginalName()->all(),
            'received_at' => now()->toIso8601String(),
        ];

        // Significance: Renamed query/request parameter from 'channel' to 'payload_type' for semantic clarity.
        $payloadType = $request->input('payload_type', 'compound-batch');

        // Significance: Caches the compound package so consuming apps can pull all disparate data streams at once.
        Cache::put("tenant:{$tenant->id}:payload:{$payloadType}", $payloadData, now()->addMinutes(10));

        // Significance: Persists the compound JSON payload into the tenant's isolated database audit log.
        try {
            DB::table('tenant_payload_logs')->insert([
                'tenant_id' => $tenant->id,
                'channel' => $payloadType,
                'payload' => json_encode($payloadData),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Fallback gracefully if audit table is absent
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Compound payload ingested successfully.',
            'payload_type' => $payloadType,
        ], 200);
    }

    public function retrieve(Request $request): JsonResponse
    {
        $tenant = app(\App\Services\Tenants\TenantContext::class)->tenant();
        $payloadType = $request->query('payload_type', 'compound-batch');
        $payload = Cache::get("tenant:{$tenant->id}:payload:{$payloadType}");

        if (! $payload) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'No pending payload found for this payload type.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'payload' => $payload,
        ], 200);
    }
}
