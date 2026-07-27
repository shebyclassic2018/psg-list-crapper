<?php

namespace App\Services\SystemB;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PassengerListUploadClient
{
    protected ?string $token = null;

    protected string $tenantBaseUrl;

    public function __construct(
        string $tenantBaseUrl,
        protected string $username,
        protected string $password,
    ) {
        $this->tenantBaseUrl = str_starts_with($tenantBaseUrl, 'http')
            ? rtrim($tenantBaseUrl, '/')
            : 'https://' . rtrim($tenantBaseUrl, '/');
    }

    /**
     * Log in and cache the Sanctum token for the lifetime of this instance.
     */
    protected function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $response = Http::acceptJson()->post(rtrim($this->tenantBaseUrl, '/') . '/backend/api/login', [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        if (! $response->successful() || ! $response->json('token')) {
            throw new RuntimeException('System B login failed: ' . $response->body());
        }

        return $this->token = $response->json('token');
    }

    /**
     * Upload a passenger-list PDF to System B's import endpoint.
     *
     * Two retry paths, each firing at most once:
     * - needs_trip_time => true: retry with the first candidate time from
     *   available_times.
     * - "No route selected..." (route alias didn't match): look up the
     *   route by $from/$to via bus-routes/search and retry with that id.
     *
     * @param  string  $pdfContents  Raw PDF bytes.
     * @param  string|null  $from  Origin station name, used only to resolve
     *                             bus_route_id if route auto-detection fails.
     * @param  string|null  $to  Destination station name, same purpose.
     */
    public function upload(
        string $pdfContents,
        string $filename,
        ?string $travelTime = null,
        ?int $busRouteId = null,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $response = $this->post($pdfContents, $filename, $travelTime, $busRouteId);
        $result = $response->json();

        if (($result['success'] ?? false) === false && ($result['needs_trip_time'] ?? false) === true) {
            $availableTimes = $result['available_times'] ?? [];

            if (empty($availableTimes)) {
                return $result;
            }

            $result = $this->post($pdfContents, $filename, $availableTimes[0], $busRouteId)->json();
        }

        if (($result['success'] ?? false) === false
            && str_contains($result['message'] ?? '', 'No route selected')
            && $busRouteId === null
            && $from !== null
            && $to !== null
        ) {
            $resolvedRouteId = $this->findRouteId($from, $to);

            if ($resolvedRouteId !== null) {
                $result = $this->post($pdfContents, $filename, $travelTime, $resolvedRouteId)->json();
            }
        }

        return $result;
    }

    /**
     * Look up a bus_route_id by origin/destination station names via the
     * bus-routes/search endpoint. Returns null if no unambiguous match.
     */
    protected function findRouteId(string $from, string $to): ?int
    {
        $response = Http::withToken($this->token())
            ->acceptJson()
            ->get(rtrim($this->tenantBaseUrl, '/') . '/backend/api/bus-routes/search', [
                'origin' => $from,
                'destination' => $to,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $routes = $response->json();

        return count($routes) === 1 ? $routes[0]['id'] : null;
    }

    protected function post(string $pdfContents, string $filename, ?string $travelTime, ?int $busRouteId): Response
    {
        $request = Http::withToken($this->token())
            ->acceptJson()
            ->attach('file', $pdfContents, $filename, ['Content-Type' => 'application/pdf']);

        $fields = array_filter([
            'travel_time' => $travelTime,
            'bus_route_id' => $busRouteId,
        ], fn ($value) => $value !== null);

        return $request->post(rtrim($this->tenantBaseUrl, '/') . '/backend/api/bookings/import-pdf', $fields);
    }
}
