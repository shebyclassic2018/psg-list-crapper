<?php

namespace App\Services\SystemB;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Apex-level (xas.co.tz) System B calls — platform_admin only, and never
 * touches a tenant subdomain (platform_admin tokens are rejected there).
 * Used for cross-tenant orchestration: listing companies, reading a
 * company's bus routes, and revealing a company's System A (OACL)
 * credentials. Distinct from PassengerListUploadClient, which logs in as
 * a normal tenant user against one specific tenant subdomain.
 */
class PlatformClient
{
    protected ?string $token = null;

    protected string $apexBaseUrl;

    public function __construct(
        string $apexBaseUrl,
        protected string $username,
        protected string $password,
    ) {
        $this->apexBaseUrl = str_starts_with($apexBaseUrl, 'http')
            ? rtrim($apexBaseUrl, '/')
            : 'https://' . rtrim($apexBaseUrl, '/');
    }

    protected function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $response = Http::acceptJson()->post($this->apexBaseUrl . '/backend/api/login', [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        if (! $response->successful() || ! $response->json('token')) {
            throw new RuntimeException('System B apex login failed: ' . $response->body());
        }

        return $this->token = $response->json('token');
    }

    protected function client()
    {
        return Http::withToken($this->token())->acceptJson();
    }

    /**
     * @return list<array{id: int, slug: string, name: string, status: string}>
     */
    public function companies(): array
    {
        $response = $this->client()->get($this->apexBaseUrl . '/backend/api/companies', ['per_page' => 500]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to list companies: ' . $response->body());
        }

        return $response->json('data') ?? $response->json() ?? [];
    }

    /**
     * @return array{username: string, password: string, tenant_username: ?string, tenant_password: ?string, before_minutes: ?int, after_minutes: ?int}|null
     */
    public function revealOaclCredentials(int $companyId): ?array
    {
        $response = $this->client()->get($this->apexBaseUrl . "/backend/api/companies/{$companyId}/oacl-credentials/reveal");

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException("Failed to reveal OACL credentials for company {$companyId}: " . $response->body());
        }

        return [
            'username' => $response->json('username'),
            'password' => $response->json('password'),
            'tenant_username' => $response->json('tenant_username'),
            'tenant_password' => $response->json('tenant_password'),
            'before_minutes' => $response->json('before_minutes'),
            'after_minutes' => $response->json('after_minutes'),
        ];
    }

    /**
     * A company's bus routes (origin/destination pairs). Uses the apex-only
     * companies/{company}/bus-routes endpoint (reads the DB directly via
     * TenantContext) rather than the tenant-scoped bus-routes endpoint,
     * since a platform_admin token is rejected outright on tenant
     * subdomains (EnsureTenantUser) — this avoids needing a per-tenant
     * user login just to discover routes.
     *
     * @return list<array{id: int, origin: string, destination: string}>
     */
    public function busRoutesForCompany(int $companyId): array
    {
        $response = $this->client()->get($this->apexBaseUrl . "/backend/api/companies/{$companyId}/bus-routes");

        if (! $response->successful()) {
            throw new RuntimeException("Failed to list bus routes for company {$companyId}: " . $response->body());
        }

        return $response->json() ?? [];
    }
}
