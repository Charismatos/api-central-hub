# Shared platform deployment and tenant isolation

This repository is a starting point for one Laravel application serving multiple
tenant clients. It is not a provisioned production platform and the tenant count
alone is not evidence that a particular server size can handle the workload.
The stated portfolio (60 e-commerce, 40 ERP, 70 SaaS, and 400 service tenants)
is 570 tenants, but peak request rate, data volume, background work, and
availability targets still need to be measured.

## What the application currently supports

- Tenants exchange their API key and client secret for a Sanctum token.
- Tenant API requests identify the tenant with `X-Tenant-Key` or a verified
  tenant domain. The authenticated Sanctum principal must be that same tenant.
- Tenant context is scoped to the current request/job and cleared in `finally`.
- Tenant-owned Eloquent models can extend `TenantOwnedModel`. The base model
  applies a tenant filter, assigns `tenant_id` on saves, uses the central
  connection for shared tenants, and uses an explicit `tenant_data` connection
  for tenants with isolated database configuration.
- Tenant database credentials are encrypted at rest. Existing JSON connection
  configurations are converted by a forward migration.
- Custom domains require a DNS TXT challenge before they can identify a tenant.
- Horizon has queues for `default`, `critical-fintech`, and `ecommerce-sync`.

The current protected-resource endpoint is still only a connectivity example.
This repository does not yet contain the e-commerce, ERP, SaaS, or service-site
business APIs, tenant business tables, automated database-copy/cutover tooling,
or per-tenant cache/file/email routing. Every tenant-owned table must include
`tenant_id`, and every model for such a table must extend `TenantOwnedModel`.
Raw query-builder and SQL access must add an explicit tenant constraint.

## Application and network topology

1. Put a TLS-terminating edge (load balancer or CDN/WAF) in front of at least
   two stateless Laravel app nodes running Octane with FrankenPHP. One hub
   origin may be used for a pilot, but it is a single point of failure.
2. Keep the hub's tenant registry, API credentials, Sanctum tokens, and shared
   tenant data on a private central database. Use private networking, least
   privilege database accounts, encrypted connections, and connection limits.
3. Use Redis for shared cache, sessions, and production queues. Run Horizon as
   a separate supervised process; do not rely on an HTTP app process to consume
   jobs. Keep critical and bulk queues independently scalable.
4. Put uploaded files and generated assets in S3-compatible object storage,
   not an individual app node's local disk. Configure lifecycle rules and
   backups for tenant data.
5. Use a transactional email provider with verified sending domains, bounce
   handling, and per-tenant sender authorization. Email is not provided by the
   application host itself.
6. Add centralized logs, exception reporting, metrics, tracing, database and
   Redis alerts, queue-age alerts, certificate-expiry alerts, and an external
   uptime check. Restrict Horizon and operational endpoints to authorized
   operators on a private network or VPN.

Hetzner can host compute, private networking, block storage, and object storage
components, but it does not provision this topology from application code.
Choose a database HA/backup plan and an email delivery provider separately.
Do not put public HTTP traffic directly on the database, Redis, or worker ports.

### HTTP protocol behavior

HTTP/3 is negotiated at the public edge only when the edge has TLS configured,
the client supports QUIC/HTTP/3, and UDP port 443 is reachable. If QUIC is
blocked or fails, a capable client normally retries over HTTP/2; if HTTP/2
cannot be negotiated, it falls back to HTTP/1.1. The edge-to-FrankenPHP
connection may use a different HTTP version. HTTP/3 at the tenant-to-edge hop
does not make the hub's private database or internal service connections HTTP/3.

## Tenant and domain operations

Create a tenant using the existing operator-only command and deliver the
one-time secret through a secure channel:

```sh
php artisan tenants:create --name="Example Shop" --slug=example-shop --category=ecommerce
```

Register a custom domain and publish the resulting TXT record before verifying
it. Only verified domains resolve to tenants:

```sh
php artisan tenants:domain:register example-shop shop.example.com
php artisan tenants:domain:verify shop.example.com
```

After DNS verification, provision TLS for the custom domain at the edge and
route it to the hub. Domain verification proves DNS control; it does not issue
or validate a TLS certificate. Do not trust an arbitrary `Host` header or
forwarded host from an untrusted proxy.

## Shared-to-isolated database transition

The runtime supports choosing an explicit isolated database for
`TenantOwnedModel` records. It deliberately does not change Laravel's global
default connection, so tenant registry/auth/token operations remain central.
`connection_config` is encrypted using `APP_KEY`; retain that key in a secret
manager and include it in protected disaster-recovery procedures. Losing it
makes encrypted client secrets and isolated connection credentials unreadable.

The connection configuration is a routing pointer, not a data migration. No
business tables or copy/checksum mechanism currently exist here, so do not mark
a tenant migrated merely by setting `connection_config`. Once data contracts
exist, use a per-tenant migration runbook:

1. Inventory the tenant's tables, row counts, foreign keys, large objects,
   scheduled jobs, and external integrations.
2. Provision the target database privately, apply the same versioned schema,
   encrypt its credentials, and verify backups and restore before use.
3. Perform an initial consistent copy, then either pause writes or use a
   validated change-capture strategy. Compare per-table counts and
   deterministic checksums; investigate every mismatch.
4. Quiesce tenant writes and jobs, perform a final sync, verify critical reads,
   and then cut over the connection pointer in a controlled maintenance window.
5. Keep the source read-only and recoverable through an agreed rollback window.
   Rollback writes must be reconciled before resuming both sides.
6. Record operator, timestamps, source/target identifiers, verification
   results, and rollback status without logging credentials or payload secrets.

For tenant isolation beyond the database (separate app nodes/workers, cache,
object storage, or email), those resource boundaries and credentials must also
be designed and migrated. This project does not automatically move an entire
tenant stack.

## Production settings and reliability gates

- Set `APP_ENV=production`, `APP_DEBUG=false`, a stable strong `APP_KEY`, and
  HTTPS-only public URLs. Keep secrets out of Git and container images.
- Use a supported HA database, Redis with authentication/TLS/private access,
  object storage, and a mail provider. Do not use database-backed sessions,
  cache, or queues as a substitute for shared production infrastructure without
  capacity and failure testing.
- Configure FrankenPHP explicitly (`OCTANE_SERVER=frankenphp`) and run Octane
  behind the edge proxy. Set trusted proxies narrowly and verify health checks
  from outside the private network.
- Run schema migrations once per release, then deploy/reload web and Horizon
  workers. Verify workers consume all configured queues and observe queue age.
- Set `HORIZON_AUTHORIZED_EMAILS` to an explicit comma-separated operator
  allowlist in production. An empty list denies dashboard access outside local
  development. Put the dashboard behind the private network/VPN as well.
- Set explicit tenant data retention, deletion, privacy, restore, RPO, and RTO
  policies. Back up the central database, isolated databases, Redis data where
  required, and object storage; encrypt backups and test restores regularly.
- Load-test a staging environment with representative tenants, data sizes,
  cache hit/miss ratios, authentication/token reuse, read/write mix, uploads,
  queue bursts, and failure scenarios. Start with the protected API smoke
  harness in `tests/load/tenant-api.js`; it does not represent business API
  throughput. Create a JSON file outside the repository containing an array of
  `{"api_key":"...","access_token":"..."}` staging-tenant pairs, set its mode
  to `0600`, and run, for example:

  ```sh
  HUB_BASE_URL=https://staging.example.com \
  TENANTS_FILE=/secure/staging-tenant-tokens.json \
  LOAD_TARGET_RATE=50 LOAD_PREALLOCATED_VUS=20 LOAD_MAX_VUS=200 \
  k6 run tests/load/tenant-api.js
  ```

  Adjust rate and duration only against an isolated staging environment; never
  use production credentials or run an unapproved stress test. Define p95/p99
  latency, error-rate, queue-age, and resource acceptance targets before
  testing.
- Scale app, database, Redis, and worker capacity based on measured saturation
  and headroom. There is no defensible universal server size for 570 tenants.

The database-configuration encryption migration requires the deployment's
current `APP_KEY`. Take and verify a restorable backup before deploying it.
Drain web traffic and pause queue workers for the deployment window: old code
expects plaintext JSON while new code expects encrypted values. Deploy the new
code and run `php artisan migrate --force` while the old and new workers are
stopped, then restart workers and verify tenant authentication and any
configured isolated connection before restoring traffic.

Rolling this migration back decrypts stored database credentials into the
legacy JSON format. Do not run that rollback casually in production; keep
traffic drained, protect the database and backups, and prefer a forward-fix.
The hub credentials previously pasted into chat should be treated as
compromised and rotated. Rotating `APP_KEY` needs a planned Laravel
previous-key/re-encryption procedure because it protects existing encrypted
tenant secrets and database configurations; do not simply run `key:generate`
against populated production data.
