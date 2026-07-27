<?php

namespace App\Console\Commands;

use App\Services\SystemA\OaclPassengerListScraper;
use App\Services\SystemB\PassengerListUploadClient;
use App\Services\SystemB\PlatformClient;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:sync-all-passenger-lists')]
#[Description('For every company with System A credentials, search each of its bus routes for today and upload every bus\'s passenger list once, immediately — no before/after departure-time window (multi-tenant version of app:sync-passenger-lists).')]
class SyncAllPassengerLists extends Command
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

        $totalUploaded = 0;

        foreach ($companies as $company) {
            $companyId = $company['id'];
            $slug = $company['slug'];

            $credentials = $platform->revealOaclCredentials($companyId);

            if ($credentials === null || empty($credentials['tenant_username']) || empty($credentials['tenant_password'])) {
                $this->line("Skipping {$slug}: missing System A or tenant-user credentials.");

                continue;
            }

            $routes = $platform->busRoutesForCompany($companyId);

            if (empty($routes)) {
                $this->line("Skipping {$slug}: no bus routes configured.");

                continue;
            }

            $totalUploaded += $this->syncCompany($slug, $credentials, $routes, $today);
        }

        $this->info("Done. Total passenger lists uploaded: {$totalUploaded}.");

        return self::SUCCESS;
    }

    protected function syncCompany(string $slug, array $credentials, array $routes, string $today): int
    {
        $uploader = new PassengerListUploadClient(
            tenantBaseUrl: "{$slug}.xas.co.tz",
            username: $credentials['tenant_username'],
            password: $credentials['tenant_password'],
        );

        $scraper = new OaclPassengerListScraper(
            baseUrl: config('systema.base_url'),
            username: $credentials['username'],
            password: $credentials['password'],
            headless: (bool) config('systema.headless'),
        );

        $uploaded = 0;

        try {
            $scraper->start();
            $scraper->login();

            $this->info("{$slug}: searching " . count($routes) . " route(s) for {$today}.");

            foreach ($routes as $route) {
                $uploaded += $this->syncRoute($scraper, $uploader, $route['origin'], $route['destination'], $today);
            }
        } finally {
            $scraper->stop();
        }

        return $uploaded;
    }

    protected function syncRoute(
        OaclPassengerListScraper $scraper,
        PassengerListUploadClient $uploader,
        string $origin,
        string $destination,
        string $date,
    ): int {
        try {
            $rows = $scraper->searchRoute($origin, $destination, $date);
        } catch (Throwable $e) {
            $this->error("  {$origin} -> {$destination}: search failed — {$e->getMessage()}");

            return 0;
        }

        $this->line("  {$origin} -> {$destination}: " . count($rows) . ' bus(es).');

        $uploaded = 0;

        foreach ($rows as $rowIndex) {
            $busName = $scraper->busNameFor($rowIndex);
            $departureTime = $scraper->departureTimeFor($rowIndex);

            try {
                $pdf = $scraper->downloadPassengerListPdf($rowIndex);

                if ($pdf === null) {
                    $this->warn("    {$busName} @ {$departureTime}: could not retrieve PDF.");

                    continue;
                }

                $result = $uploader->upload($pdf, "passenger-list-{$rowIndex}.pdf", $departureTime, from: $origin, to: $destination);

                if ($result['success'] ?? false) {
                    $this->info("    {$busName} @ {$departureTime}: imported ({$result['imported']} passengers).");
                    $uploaded++;
                } else {
                    $this->warn("    {$busName} @ {$departureTime}: import failed — " . ($result['message'] ?? 'unknown error'));
                }
            } catch (Throwable $e) {
                $this->error("    {$busName} @ {$departureTime}: exception — {$e->getMessage()}");
            }
        }

        return $uploaded;
    }
}
