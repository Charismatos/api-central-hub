# API Central Hub: Laravel Sail Tenant Client Examples

This guide shows four independent Laravel Sail applications connecting to this
hub over HTTP:

1. E-commerce
2. ERP
3. Risk-Control Consultancy
4. SaaS

Each application keeps its own database and business data. A tenant application
does **not** connect to the hub by sharing its database credentials. It calls
the hub's API, first exchanging its API key and client secret for a Sanctum
token and then sending that token together with `X-Tenant-Key`.

The examples use the hub routes that currently exist:

- `POST /api/v1/oauth/token`
- `GET /api/v1/tenant/protected-resource`

The hub-side source files for this contract are
[routes/api.php](../routes/api.php),
[TenantAuthController.php](../app/Http/Controllers/Tenants/TenantAuthController.php),
[TenantAuthService.php](../app/Services/Tenants/TenantAuthService.php), and
[ResolveTenantConnection.php](../app/Http/Middleware/Tenants/ResolveTenantConnection.php).

The protected resource is currently a connectivity demonstration, not an
e-commerce, ERP, consultancy, or SaaS business API. Build and document those
business endpoints in the hub before expecting tenant projects to submit
orders, ERP records, audit reports, or SaaS events.

## Important: hub-side tenant/token authorization is a go-live prerequisite

The current hub middleware resolves a tenant from `X-Tenant-Key`, while
Sanctum authenticates the bearer token separately. The current protected route
does not verify that the authenticated token belongs to the tenant resolved
from the header. Until the hub enforces that relationship, a valid token and a
different active tenant key may be accepted together. The client examples
below always send matching credentials, but client behavior is not a
replacement for server-side authorization. Enforce the token-to-tenant match
in the hub before production use.

## Tenant credential generation and onboarding

The credentials are generated **after the hub operator approves/provisions a
tenant and before its Sail application is configured**. The actor is a trusted
API Central Hub operator with shell access to the hub environment; a tenant
client, website visitor, or public API caller does not generate credentials.

Run the new operator-only Artisan command once for each approved tenant:

```bash
# What: Creates the e-commerce tenant and generates its initial API key and client secret.
# Where: Run from the API Central Hub project root or its running application container.
# When: After an operator approves onboarding and before configuring the tenant's Sail environment.
# Who: A trusted hub operator with authorized shell access; not the tenant's public-facing application.
# Why: Keeps credential issuance out of unauthenticated HTTP routes and binds each credential pair to one tenant.
php artisan tenants:create --name="East Africa Retail" --slug=east-africa-retail --vertical=ecommerce

# What: Creates a separate ERP tenant and credential pair.
# Where: Same hub project/authorized runtime.
# When: During the ERP tenant's approved onboarding.
# Who: The authorized hub operator.
# Why: Prevents the ERP app from reusing another tenant's identity.
php artisan tenants:create --name="Nile Logistics" --slug=nile-logistics --vertical=erp

# What: Creates the Risk-Control Consultancy tenant and its credentials.
# Where: Same hub project/authorized runtime.
# When: During the consultancy website's approved onboarding.
# Who: The authorized hub operator.
# Why: Gives this integration its own isolated tenant identity at the hub.
php artisan tenants:create --name="Risk Control Consultancy" --slug=risk-control-consultancy --vertical=risk-control-consultancy

# What: Creates the SaaS installation tenant and a distinct credential pair.
# Where: Same hub project/authorized runtime.
# When: During approved onboarding of the SaaS installation.
# Who: The authorized hub operator.
# Why: Makes the SaaS installation distinct; provision customers separately only if the product's tenancy model requires it.
php artisan tenants:create --name="Acme SaaS" --slug=acme-saas --vertical=saas
```

For each successful command, the hub generates a unique `thub_` API key and a
high-entropy client secret, stores the secret encrypted, sets the tenant to
`active`, and prints both credentials once. Copy them directly into that
tenant application's protected environment/secret manager as
`API_CENTRAL_HUB_API_KEY` and `API_CENTRAL_HUB_CLIENT_SECRET`. The raw secret
cannot be recovered from the encrypted database value for later display;
rotation should be an explicit operator action. Do not copy real credentials
into documentation, issue trackers, shell transcripts, CI logs, or Git.

This project intentionally does **not** expose a public onboarding endpoint.
The operator command validates the name, slug, and vertical; rejects duplicate
slugs; and does not create or alter tenant records for rejected input. The
feature coverage is in
[CreateTenantCommandTest.php](../tests/Feature/Tenants/CreateTenantCommandTest.php):
positive creation, negative invalid-slug rejection, and the duplicate-slug
edge case.

## Sail networking: choose the hub URL that the tenant container can reach

`localhost` inside a tenant container refers to that tenant container itself,
not the hub or the developer's host machine.

- If the hub publishes port `8080` on the host, a tenant container can
  generally use `http://host.docker.internal:8080`. On Linux, add
  `extra_hosts: ["host.docker.internal:host-gateway"]` to the tenant app's
  Sail service if the Docker setup does not already provide that hostname.
- If both projects share a Docker network, prefer the hub's network service
  name and port, for example `http://api-central-hub` (use the actual network
  alias and container port).
- In production, use the hub's HTTPS URL, such as
  `https://hub.example.com`. Do not send tenant secrets over plain HTTP across
  an untrusted network.

Confirm the hub's published port and route prefix in the actual deployment.
The examples below use `http://host.docker.internal:8080` as a local
placeholder.

## 1. E-commerce Sail project

Example tenant identity: `east-africa-retail`. Create its corresponding active
tenant record in the hub and securely issue unique credentials to this one
e-commerce installation.

### `.env`

```dotenv
# What: Points this container to the hub's HTTP origin, without an API path.
# Where: The e-commerce project's local environment file; change to the reachable hub URL.
# When: Read while Laravel loads config/services.php and builds its config cache.
# Who: The e-commerce app's deployment operator supplies the environment-specific address.
# Why: Keeps Docker networking and production hosts out of source-controlled PHP.
API_CENTRAL_HUB_BASE_URL=http://host.docker.internal:8080

# What: Identifies the e-commerce tenant provisioned in the hub.
# Where: Secret environment configuration for this e-commerce installation.
# When: Sent in the token request body and as X-Tenant-Key on protected calls.
# Who: The hub administrator issues this value for the east-africa-retail tenant.
# Why: Lets the hub select the active tenant record; it is not a substitute for the bearer token.
API_CENTRAL_HUB_API_KEY=<E_COMMERCE_TENANT_API_KEY>

# What: Supplies the raw client secret paired with the e-commerce tenant API key.
# Where: Secret environment configuration, never committed to Git.
# When: Sent only to POST /api/v1/oauth/token over HTTPS or trusted local development networking.
# Who: The hub administrator securely delivers it to the e-commerce deployment operator.
# Why: Proves possession of the tenant credential so the hub can issue a Sanctum token.
API_CENTRAL_HUB_CLIENT_SECRET=<E_COMMERCE_TENANT_CLIENT_SECRET>
```

### `config/services.php`

Add this entry inside the returned array. Keep environment reads in a config
file so `php artisan config:cache` works correctly.

```php
// What: Registers the hub as an external service for the e-commerce app.
// Where: The top-level array returned by config/services.php.
// When: Laravel loads application configuration, including when it is cached.
// Who: Laravel's configuration repository and ApiCentralHubClient consume these values.
// Why: Centralizes the base URL and this tenant's credentials without hard-coding them in service code.
'api_central_hub' => [
    // What: Hub origin only; the client appends the versioned API paths.
    // Where: services.api_central_hub.base_url.
    // When: Each outbound hub request is created.
    // Who: The e-commerce deployment operator sets API_CENTRAL_HUB_BASE_URL.
    // Why: Allows the same client code to work in Sail, staging, and production.
    'base_url' => env('API_CENTRAL_HUB_BASE_URL', ''),

    // What: Public tenant identifier issued for east-africa-retail.
    // Where: services.api_central_hub.api_key.
    // When: Authentication and tenant-scoped requests are sent.
    // Who: The e-commerce app reads this through Laravel config, not env() at runtime.
    // Why: Gives the hub the intended tenant context for this installation.
    'api_key' => env('API_CENTRAL_HUB_API_KEY', ''),

    // What: Private proof used to exchange credentials for a bearer token.
    // Where: services.api_central_hub.client_secret.
    // When: The token is first acquired or refreshed.
    // Who: The e-commerce app reads this through Laravel config.
    // Why: Keeps the raw secret out of source files and ordinary application logic.
    'client_secret' => env('API_CENTRAL_HUB_CLIENT_SECRET', ''),
],
```

## 2. ERP Sail project

Example tenant identity: `nile-logistics`. Provision a distinct active hub
tenant for this ERP deployment. Do not copy the e-commerce tenant's API key or
client secret.

### `.env`

```dotenv
# What: Hub origin reachable from the ERP Sail container.
# Where: The ERP project's environment configuration.
# When: Laravel loads the ERP service configuration.
# Who: The ERP deployment operator selects the local network address or production HTTPS host.
# Why: The ERP project calls the hub over HTTP rather than sharing its database.
API_CENTRAL_HUB_BASE_URL=http://host.docker.internal:8080

# What: Unique tenant key provisioned for the nile-logistics ERP tenant.
# Where: The ERP deployment's secret environment.
# When: Sent on authentication and protected requests.
# Who: The hub administrator issues it for this ERP tenant only.
# Why: Prevents this installation from accidentally identifying itself as another tenant.
API_CENTRAL_HUB_API_KEY=<ERP_TENANT_API_KEY>

# What: Unique client secret paired with the ERP API key.
# Where: The ERP deployment's secret environment, excluded from version control.
# When: Sent to the hub token endpoint when the client needs a token.
# Who: The hub administrator securely provides it to the ERP operator.
# Why: Authenticates the ERP tenant without putting credentials in source code.
API_CENTRAL_HUB_CLIENT_SECRET=<ERP_TENANT_CLIENT_SECRET>
```

### `config/services.php`

Use the same `api_central_hub` configuration entry shown in the e-commerce
example. Laravel code should read `config('services.api_central_hub...')`,
while this file maps those values to environment variables.

## 3. Risk-Control Consultancy Sail project

Example tenant identity: `ris-control-consultancy` (a readable slug for the
requested “Ris-Control-Consutancy” example). Provision a distinct active hub
tenant for the consultancy's website or back-office application.

### `.env`

```dotenv
# What: Hub origin the consultancy's Sail containers can reach.
# Where: The consultancy project's environment configuration.
# When: Laravel loads config/services.php or builds its configuration cache.
# Who: The consultancy deployment operator chooses the reachable URL.
# Why: Keeps the website's network location separate from the hub API paths.
API_CENTRAL_HUB_BASE_URL=http://host.docker.internal:8080

# What: Tenant identifier provisioned specifically for the consultancy.
# Where: The consultancy deployment's protected environment configuration.
# When: Sent in the token request and X-Tenant-Key header.
# Who: The hub administrator issues this key for ris-control-consultancy.
# Why: Makes the consultancy a separate tenant instead of sharing another project's identity.
API_CENTRAL_HUB_API_KEY=<RISK_CONTROL_TENANT_API_KEY>

# What: Raw secret paired with the consultancy's API key.
# Where: A secret manager or untracked local/deployment environment, never committed.
# When: Used to acquire a bearer token from the hub.
# Who: The hub administrator securely supplies it to the consultancy operator.
# Why: Demonstrates the tenant's identity to the hub without storing the secret in PHP code.
API_CENTRAL_HUB_CLIENT_SECRET=<RISK_CONTROL_TENANT_CLIENT_SECRET>
```

### `config/services.php`

Use the same `api_central_hub` configuration entry shown in the e-commerce
example. The consultancy may call the current protected-resource endpoint as a
connectivity check; do not treat that endpoint as an audit-report or
risk-control submission API.

## 4. SaaS Sail project

Example tenant identity: `acme-saas`. If this SaaS product serves multiple
customer organizations, decide deliberately whether the **SaaS installation**
is one hub tenant or whether each customer organization is independently
provisioned as a hub tenant. These examples show one tenant per SaaS
installation. A shared SaaS installation must not put different customers'
hub credentials in an application-wide configuration value.

### `.env`

```dotenv
# What: Hub origin reachable from this SaaS Sail deployment.
# Where: The SaaS application's deployment environment.
# When: Laravel loads the service configuration and sends hub requests.
# Who: The SaaS operator selects the environment-specific hub address.
# Why: Lets one codebase connect to local, staging, or production hub environments.
API_CENTRAL_HUB_BASE_URL=http://host.docker.internal:8080

# What: API key issued for this acme-saas installation.
# Where: The SaaS deployment's secret configuration.
# When: Included in token exchange and protected requests.
# Who: The hub administrator provisions the SaaS tenant and issues this key.
# Why: Identifies the SaaS tenant to the hub independently of the SaaS app's own user login.
API_CENTRAL_HUB_API_KEY=<SAAS_TENANT_API_KEY>

# What: Private credential used only to obtain a hub bearer token.
# Where: The SaaS deployment's secret manager or untracked environment file.
# When: Used during initial token acquisition and token refresh.
# Who: The hub administrator securely delivers it to the SaaS operator.
# Why: Prevents customer-facing users or source control from receiving this server-to-server secret.
API_CENTRAL_HUB_CLIENT_SECRET=<SAAS_TENANT_CLIENT_SECRET>
```

### `config/services.php`

Use the same `api_central_hub` configuration entry shown in the e-commerce
example. For multi-tenant SaaS where each customer has separate hub
credentials, store each credential pair in an appropriately protected
tenant-specific secret store and resolve it only after authorizing the
customer; do not use one global `.env` key for all customers.

## Shared Laravel client class

Copy this class into **each tenant application's**
`app/Services/Integrations/ApiCentralHubClient.php`. The only differences
between the four projects are their environment-specific tenant credentials
and business code that calls the client. This class intentionally calls only
the hub's existing protected-resource endpoint.

```php
<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use UnexpectedValueException;

/**
 * What: Encapsulates the tenant app's server-to-server connection to the hub.
 * Where: app/Services/Integrations in each tenant Laravel project.
 * When: An authorized app service, job, or command needs to call a hub API.
 * Who: Laravel's service container creates this client for tenant-side code.
 * Why: Keeps token exchange, required headers, timeouts, and response errors consistent.
 */
class ApiCentralHubClient
{
    /**
     * What: Performs a safe connectivity check against the hub's current protected example route.
     * Where: Public method of the tenant-side hub client.
     * When: Operators verify connectivity or application code needs this existing read-only endpoint.
     * Who: The tenant app calls this method; the hub's tenant middleware and Sanctum validate the request.
     * Why: Proves the configured URL, API key, secret, token, and tenant header can reach the hub together.
     *
     * @return array<string, mixed>
     */
    public function checkConnection(): array
    {
        // What: Identify the tenant's API key and the cache entry for its bearer token.
        // Where: Before making the protected hub request.
        // When: Every connection check; the API key is also sent as X-Tenant-Key.
        // Who: The client obtains the key through Laravel's cached configuration.
        // Why: The hashed cache key avoids placing the raw API key in the cache-key string.
        $apiKey = $this->requiredConfig('api_key');
        $cacheKey = $this->tokenCacheKey($apiKey);

        // What: Make the protected GET request with both required tenant credentials.
        // Where: The current hub route GET /api/v1/tenant/protected-resource.
        // When: After retrieving or acquiring a short-lived cached bearer token.
        // Who: The hub middleware resolves X-Tenant-Key and Sanctum checks Authorization.
        // Why: Both the tenant identifier and bearer token are part of the current route contract.
        $response = $this->requestWithTenantCredentials($apiKey);

        // What: Discard a cached token if the hub explicitly rejects it, then retry this safe GET once.
        // Where: Token recovery branch in the client method.
        // When: A token was revoked or the cached credential is no longer accepted.
        // Who: The tenant client refreshes; the hub remains responsible for authorization.
        // Why: A read-only GET can be retried after authentication failure without duplicating a write.
        if ($response->unauthorized()) {
            Cache::forget($cacheKey);
            $response = $this->requestWithTenantCredentials($apiKey);
        }

        // What: Convert unsuccessful HTTP responses into explicit Laravel exceptions.
        // Where: Before reading the response body as a successful result.
        // When: The hub reports a non-success status, such as invalid credentials or a server error.
        // Who: Laravel's HTTP client raises the exception for tenant-side error handling and logging.
        // Why: Prevents an error response from looking like a successful empty API payload.
        $payload = $response->throw()->json();

        // What: Enforce the documented JSON-object response shape for this endpoint.
        // Where: Client response boundary.
        // When: A successful HTTP status contains a missing or unexpected JSON structure.
        // Who: Tenant-side integration code receives a clear exception instead of malformed data.
        // Why: A 2xx status alone does not guarantee that the expected payload exists.
        if (! is_array($payload)) {
            throw new UnexpectedValueException('The API Central Hub returned an unexpected JSON response.');
        }

        return $payload;
    }

    /**
     * What: Sends the existing protected-resource GET with a tenant key and Sanctum bearer token.
     * Where: Private transport method for the public connectivity check.
     * When: Initially and once more if the cached bearer token receives HTTP 401.
     * Who: The tenant client sends the request; hub middleware and Sanctum validate it.
     * Why: Centralizes the required headers so callers cannot accidentally omit the tenant key.
     */
    private function requestWithTenantCredentials(string $apiKey): \Illuminate\Http\Client\Response
    {
        return $this->http()
            ->withToken($this->token($apiKey))
            ->withHeader('X-Tenant-Key', $apiKey)
            ->get('/api/v1/tenant/protected-resource');
    }

    /**
     * What: Returns a cached bearer token or exchanges the tenant's API key and secret for one.
     * Where: Private authentication method for outbound hub calls.
     * When: On the first call, after the 55-minute cache lifetime, or after a 401 response.
     * Who: The tenant app authenticates with TenantAuthController in the hub.
     * Why: Avoids creating a new Sanctum token on every tenant application request.
     */
    private function token(string $apiKey): string
    {
        // What: Reuse the tenant-specific token during its conservative local cache window.
        // Where: The tenant app's configured Laravel cache store.
        // When: Until 55 minutes after issue, or until this cache entry is explicitly forgotten.
        // Who: Web workers and queue workers can share the value when configured with a shared cache store.
        // Why: The hub response has no expires_in field, so the client refreshes periodically instead of assuming permanence.
        return Cache::remember($this->tokenCacheKey($apiKey), now()->addMinutes(55), function () use ($apiKey): string {
            // What: Exchange the tenant's credentials for the hub's Sanctum access token.
            // Where: POST /api/v1/oauth/token on the configured hub origin.
            // When: No cached token exists or the previous cached token was rejected.
            // Who: TenantAuthController validates the API key and client secret.
            // Why: The client secret is needed only for token acquisition, not on ordinary protected calls.
            $response = $this->http()->post('/api/v1/oauth/token', [
                'api_key' => $apiKey,
                'client_secret' => $this->requiredConfig('client_secret'),
            ])->throw();

            // What: Extract the access_token field returned by the hub.
            // Where: Successful token response parsing.
            // When: The hub responds successfully to the credential exchange.
            // Who: The tenant client consumes the field returned by TenantAuthController.
            // Why: Rejects a malformed success response instead of sending an empty bearer token.
            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new UnexpectedValueException('The API Central Hub token response did not contain an access token.');
            }

            return $token;
        });
    }

    /**
     * What: Creates a consistently configured HTTP client for hub requests.
     * Where: Private HTTP-client factory within the integration service.
     * When: Making either the token request or protected resource request.
     * Who: Laravel's HTTP client sends the request from the tenant application.
     * Why: Explicit timeouts limit how long a hub outage can hold a tenant worker.
     */
    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10);
    }

    /**
     * What: Reads a required hub setting from Laravel's configuration repository.
     * Where: Tenant app configuration boundary.
     * When: A request needs the configured base URL, API key, or client secret.
     * Who: Laravel config:cache and the deployment environment provide the value.
     * Why: Fails clearly on missing configuration and remains compatible with cached configuration.
     */
    private function requiredConfig(string $key): string
    {
        $value = config("services.api_central_hub.{$key}");

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The API Central Hub configuration value [{$key}] is required.");
        }

        return $value;
    }

    /**
     * What: Validates and normalizes the configured hub origin.
     * Where: HTTP client construction.
     * When: Before any authentication or protected request is sent.
     * Who: The deployment operator supplies the origin through API_CENTRAL_HUB_BASE_URL.
     * Why: Avoids sending requests to an implicit or malformed default host.
     */
    private function baseUrl(): string
    {
        return rtrim($this->requiredConfig('base_url'), '/');
    }

    /**
     * What: Builds a tenant-specific cache key without embedding the raw API key.
     * Where: The client's token cache helpers.
     * When: Reading, writing, or clearing the cached bearer token.
     * Who: Laravel's cache store uses the returned key.
     * Why: Separates credentials for different tenant identities and limits accidental key disclosure in cache tooling.
     */
    private function tokenCacheKey(string $apiKey): string
    {
        return 'api-central-hub:token:'.hash('sha256', $apiKey);
    }
}
```

## Use the client from each project

Copy the client class and configuration into each tenant project, then use its
connection check from an operator-only command, a queued integration job, or
another server-side service. Do not expose a route that returns the bearer
token or client secret to a browser.

For a quick local check in each project:

```bash
# What: Runs the connection check inside the tenant application's Sail container.
# Where: The tenant Laravel project root.
# When: After adding config, setting the tenant environment values, and starting Sail.
# Who: A developer or deployment operator with access to that tenant environment.
# Why: Confirms end-to-end reachability without exposing credentials in a browser response.
./vendor/bin/sail artisan tinker --execute='dump(app(\App\Services\Integrations\ApiCentralHubClient::class)->checkConnection());'
```

Expected response shape from the hub's current demonstration route:

```json
{
  "status": "success",
  "message": "Successfully accessed central hub protected resource.",
  "tenant": {
    "uuid": "hub-issued-tenant-uuid",
    "name": "Provisioned tenant name",
    "vertical": "ecommerce"
  }
}
```

The `vertical` in the response must match the value stored on the provisioned
hub tenant. The example uses `ecommerce`; the other tenant projects should
receive their own stored vertical value.

## Final setup checklist

- Provision one active hub tenant per tenant identity and securely deliver its
  unique API key and raw client secret.
- Replace every placeholder in `.env`; keep populated `.env` files out of Git.
- Set `API_CENTRAL_HUB_BASE_URL` to an address reachable **from the Sail
  container**, and use HTTPS outside trusted local development.
- Add the `api_central_hub` entry to `config/services.php` in each tenant app.
- Copy `ApiCentralHubClient` to each tenant app and run
  `./vendor/bin/sail artisan config:clear` after changing local configuration.
- Configure a shared cache store for web and queue containers if they must
  reuse the same token cache. Token values are credentials; restrict cache
  access accordingly.
- Verify the hub enforces that the Sanctum token's tenant matches
  `X-Tenant-Key` before going live.
- Treat the current protected-resource route only as a connectivity check.
  Build tenant-specific hub APIs before sending business records.
