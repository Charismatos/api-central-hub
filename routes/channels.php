<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * What: Authorizes whether a connected user/client has permission to listen to a specific tenant's live channel.
 * Where: WebSocket channel routing file (`routes/channels.php`).
 * When: Called when a client attempts to subscribe to `private-tenant.{uuid}`.
 * Who: Laravel Broadcast Authorization Guard.
 * Why: Secures enterprise and fintech data streams against unauthorized wiretapping or eavesdropping.
 */
Broadcast::channel('tenant.{uuid}', function ($user, $uuid) {
    // Verify if the authenticated user belongs to this tenant or is a central system admin
    return $user->tenant_uuid === $uuid || $user->hasRole('super-admin');
});


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');