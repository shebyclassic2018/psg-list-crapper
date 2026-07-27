<?php

namespace App\Console\Commands;

use App\Services\SystemA\OaclPassengerListScraper;
use App\Services\SystemB\PassengerListUploadClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:sync-passenger-lists {from} {to} {date}')]
#[Description('Log into System A, download each bus\'s passenger list for a route/date, and import each into System B.')]
class SyncPassengerLists extends Command
{
    public function handle(): int
    {
        $from = $this->argument('from');
        $to = $this->argument('to');
        $date = $this->argument('date');

        $scraper = new OaclPassengerListScraper(
            baseUrl: config('systema.base_url'),
            username: config('systema.username'),
            password: config('systema.password'),
            headless: (bool) config('systema.headless'),
        );

        $uploader = new PassengerListUploadClient(
            tenantBaseUrl: config('systemb.tenant_base_url'),
            username: config('systemb.username'),
            password: config('systemb.password'),
        );

        $imported = 0;
        $failed = 0;

        try {
            $scraper->start();
            $scraper->login();

            $rows = $scraper->searchRoute($from, $to, $date);
            $this->info(sprintf('Found %d buses for %s -> %s on %s.', count($rows), $from, $to, $date));

            foreach ($rows as $rowIndex) {
                try {
                    $departureTime = $scraper->departureTimeFor($rowIndex);
                    $pdf = $scraper->downloadPassengerListPdf($rowIndex);

                    if ($pdf === null) {
                        $this->warn("Row {$rowIndex}: could not retrieve passenger list PDF, skipping.");
                        $failed++;

                        continue;
                    }

                    $result = $uploader->upload($pdf, "passenger-list-row-{$rowIndex}.pdf", $departureTime, from: $from, to: $to);

                    if ($result['success'] ?? false) {
                        $this->info("Row {$rowIndex}: imported ({$result['imported']} passengers).");
                        $imported++;
                    } else {
                        $this->warn("Row {$rowIndex}: import failed — " . ($result['message'] ?? 'unknown error'));
                        $failed++;
                    }
                } catch (Throwable $e) {
                    $this->error("Row {$rowIndex}: exception — {$e->getMessage()}");
                    $failed++;
                }
            }
        } finally {
            $scraper->stop();
        }

        $this->info("Done. Imported: {$imported}, Failed: {$failed}.");

        return $failed > 0 && $imported === 0 ? self::FAILURE : self::SUCCESS;
    }
}
