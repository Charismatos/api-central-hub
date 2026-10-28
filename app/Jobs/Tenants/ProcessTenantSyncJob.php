<?php

namespace App\Jobs\Tenants;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTenantSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Tenant $tenant;
    protected array $payloadData;

    /**
     * What: Initializes the background job with the target tenant and payload data.
     * Where: Job constructor.
     * When: Dispatched from controllers when a tenant triggers a sync or webhook action.
     * Who: Application Worker.
     * Why: Encapsulates asynchronous processing tasks so HTTP request threads remain fast under Octane.
     */
    public function __construct(Tenant $tenant, array $payloadData)
    {
        $this->tenant = $tenant;
        $this->payloadData = $payloadData;
        
        // Push this job to a specialized queue depending on tenant vertical (e.g., Fintech gets priority)
        $this->onQueue($tenant->vertical === 'fintech' ? 'critical-fintech' : 'ecommerce-sync');
    }

    /**
     * What: Assigns Horizon tags to the job for real-time filtering in the dashboard.
     * Where: Horizon tracking layer.
     * When: Invoked when the job is pushed to the Redis queue.
     * Who: Laravel Horizon.
     * Why: Enables granular tracking and debugging per tenant (e.g., filtering logs by `tenant:UUID`).
     */
    public function tags(): array
    {
        return ['tenant:' . $this->tenant->uuid, 'vertical:' . $this->tenant->vertical];
    }

    /**
     * What: Executes the core background synchronization or data processing logic.
     * Where: Queue worker process.
     * When: Picked up and processed by an active Horizon worker.
     * Who: Background Worker Daemon.
     * Why: Offloads heavy compute tasks away from the main web server, protecting hosting performance.
     */
    public function handle(): void
    {
        // Bind the tenant into the container during job execution
        app()->instance(Tenant::class, $this->tenant);

        // Perform multi-tenant business logic (e.g., ERP synchronization, webhook dispatch)
        // ...
    }
}