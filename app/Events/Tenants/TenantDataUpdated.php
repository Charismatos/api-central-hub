<?php

namespace App\Events;

use App\Models\Tenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantDataUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Tenant $tenant;
    public array $updateData;

    /**
     * What: Instantiates the real-time event with tenant context and data payload.
     * Where: Event initialization layer.
     * When: Triggered whenever a tenant record or resource changes state.
     * Who: Event Dispatcher.
     * Why: Passes contextual data instantly to connected client frontends via WebSockets.
     */
    public function __construct(Tenant $tenant, array $updateData)
    {
        $this->tenant = $tenant;
        $this->updateData = $updateData;
    }

    /**
     * What: Defines the private broadcast channel scoped explicitly to the tenant's UUID.
     * Where: Broadcasting routing layer.
     * When: Automatically evaluated when the event is broadcasted.
     * Who: Reverb WebSocket Server.
     * Why: Ensures data isolation so Tenant A (E-Commerce) cannot intercept real-time websocket packets intended for Tenant B (Fintech).
     */
    public function broadcastOn(): Channel
    {
        return new PrivateChannel('tenant.' . $this->tenant->uuid);
    }

    /**
     * What: Specifies the exact payload array sent over the WebSocket connection.
     * Where: Payload serialization layer.
     * When: Serialized immediately before transmission to the client.
     * Who: Reverb Broadcaster.
     * Why: Prevents leaking sensitive internal properties while providing clean, structured JSON to client apps.
     */
    public function broadcastWith(): array
    {
        return [
            'tenant_uuid' => $this->tenant->uuid,
            'vertical' => $this->tenant->vertical,
            'data' => $this->updateData,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}