<?php

namespace App\Console\Commands;

use App\Models\ScrapedDeparture;
use App\Services\SystemA\OaclPassengerListScraper;
use App\Services\SystemB\PlatformClient;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:discover-departures')]
#[Description('Hourly: for every company with System A credentials, search each of its bus routes for today and record every bus departure found.')]
class DiscoverDepartures extends Command
{
    public function handle(): int
    {
        $platform = new PlatformClient(
            apexBaseUrl: config('systemb.apex_base_url'),
            username: config('systemb.platform_admin_username'),
            password: config('systemb.platform_admin_password'),
        );

        $today = Carbon::today()->format('Y-m-d');
        $companies = $platform->companies();

        $this->info(sprintf('Found %d compan%s.', count($companies), count($companies) === 1 ? 'y' : 'ies'));

        $totalDiscovered = 0;

        foreach ($companies as $company) {
            $companyId = $company['id'];
            $slug = $company['slug'];

            $credentials = $platform->revealOaclCredentials($companyId);

            if ($credentials === null) {
                $this->line("Skipping {$slug}: no System A credentials set.");

                continue;
            }

            $routes = $platform->busRoutesForCompany($companyId);

            if (empty($routes)) {
                $this->line("Skipping {$slug}: no bus routes configured.");

                continue;
            }

            $this->info(sprintf('%s: searching %d route(s) for %s.', $slug, count($routes), $today));

            $scraper = new OaclPassengerListScraper(
                baseUrl: config('systema.base_url'),
                username: $credentials['username'],
                password: $credentials['password'],
                headless: (bool) config('systema.headless'),
            );

            try {
                $scraper->start();
                $scraper->login();

                foreach ($routes as $route) {
                    try {
                        $discovered = $this->discoverRoute($scraper, $slug, $route['origin'], $route['destination'], $today);
                        $totalDiscovered += $discovered;

                        $this->line("  {$route['origin']} -> {$route['destination']}: {$discovered} bus(es).");
                    } catch (Throwable $e) {
                        $this->error("  {$route['origin']} -> {$route['destination']}: exception — {$e->getMessage()}");
                    }
                }
            } finally {
                $scraper->stop();
            }
        }

        $this->info("Done. Total departures discovered/updated: {$totalDiscovered}.");

        return self::SUCCESS;
    }

    protected function discoverRoute(
        OaclPassengerListScraper $scraper,
        string $tenantSlug,
        string $origin,
        string $destination,
        string $date,
    ): int {
        $rows = $scraper->searchRoute($origin, $destination, $date);
        $count = 0;

        foreach ($rows as $rowIndex) {
            $busId = $scraper->busIdFor($rowIndex);
            $departureTime = $scraper->departureTimeFor($rowIndex);

            if ($busId === null || $departureTime === null) {
                continue;
            }

            ScrapedDeparture::updateOrCreate(
                [
                    'tenant_slug' => $tenantSlug,
                    'oacl_bus_id' => $busId,
                    'travel_date' => $date,
                ],
                [
                    'bus_name' => $scraper->busNameFor($rowIndex),
                    'origin' => $origin,
                    'destination' => $destination,
                    'departure_time' => $departureTime,
                    'departure_at' => "{$date} {$departureTime}:00",
                ]
            );

            $count++;
        }

        return $count;
    }
}
