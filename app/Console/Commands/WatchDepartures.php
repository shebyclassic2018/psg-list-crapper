<?php

namespace App\Console\Commands;

use App\Models\ScrapedDeparture;
use App\Services\SystemA\OaclPassengerListScraper;
use App\Services\SystemB\PassengerListUploadClient;
use App\Services\SystemB\PlatformClient;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('app:watch-departures')]
#[Description('Every minute: upload each due bus\'s passenger list a per-tenant number of minutes before and after its departure, retrying failed attempts until 30 minutes past departure.')]
class WatchDepartures extends Command
{
    protected const RETRY_CUTOFF_MINUTES = 30;

    /**
     * Fallback before/after windows (minutes) for tenants that haven't
     * configured their own via oacl_credentials.before_minutes/after_minutes.
     */
    protected const DEFAULT_BEFORE_MINUTES = 10;

    protected const DEFAULT_AFTER_MINUTES = 10;

    protected const SLOT_BEFORE = 'before';

    protected const SLOT_AFTER = 'after';

    public function handle(): int
    {
        $now = Carbon::now();

        // The before/after window is per-tenant (companies.oacl_credentials),
        // so candidates must be widened enough to cover the largest possible
        // configured window, then filtered per-tenant once we know each
        // tenant's actual minutes below.
        $candidates = $this->candidateDepartures($now);

        if ($candidates->isEmpty()) {
            $this->line('No departures due for upload right now.');
            Log::info("No departures due for upload right now.");

            return self::SUCCESS;
        }

        $platform = new PlatformClient(
            apexBaseUrl: config('systemb.apex_base_url'),
            username: config('systemb.platform_admin_username'),
            password: config('systemb.platform_admin_password'),
        );

        $totalDue = 0;

        foreach ($candidates->groupBy('tenant_slug') as $tenantSlug => $departures) {
            $totalDue += $this->processTenant($platform, $tenantSlug, $departures, $now);
        }

        if ($totalDue === 0) {
            $this->line('No departures due for upload right now.');
            Log::info("No departures due for upload right now.");
        }

        return self::SUCCESS;
    }

    /**
     * Every ScrapedDeparture that could plausibly still need a before/after
     * upload, regardless of tenant — the per-tenant before/after minutes are
     * only known once we fetch that tenant's credentials in processTenant(),
     * so this cast a wide net using the larger of the two defaults/cutoff.
     */
    protected function candidateDepartures(Carbon $now): Collection
    {
        $cutoff = $now->copy()->subMinutes(self::RETRY_CUTOFF_MINUTES);

        return ScrapedDeparture::where('departure_at', '>=', $cutoff)
            ->where(function ($q) {
                $q->where('upload_before_status', '!=', ScrapedDeparture::STATUS_SUCCESS)
                    ->orWhere('upload_after_status', '!=', ScrapedDeparture::STATUS_SUCCESS);
            })
            ->get();
    }

    /**
     * @return Collection<int, array{departure: ScrapedDeparture, slot: string}>
     */
    protected function dueDepartures(Collection $departures, Carbon $now, int $beforeMinutes, int $afterMinutes): Collection
    {
        $due = collect();

        foreach ($departures as $departure) {
            $beforeAt = $departure->departure_at->copy()->subMinutes($beforeMinutes);
            $afterAt = $departure->departure_at->copy()->addMinutes($afterMinutes);
            $retryDeadline = $departure->departure_at->copy()->addMinutes(self::RETRY_CUTOFF_MINUTES);

            if ($now->lt($retryDeadline)) {
                if ($now->gte($beforeAt) && $departure->upload_before_status !== ScrapedDeparture::STATUS_SUCCESS) {
                    $due->push(['departure' => $departure, 'slot' => self::SLOT_BEFORE]);
                }

                if ($now->gte($afterAt) && $departure->upload_after_status !== ScrapedDeparture::STATUS_SUCCESS) {
                    $due->push(['departure' => $departure, 'slot' => self::SLOT_AFTER]);
                }
            }
        }

        return $due;
    }

    /**
     * @param  Collection<int, ScrapedDeparture>  $departures  Candidate departures for this tenant (not yet filtered to due slots).
     * @return int Number of due slots processed.
     */
    protected function processTenant(PlatformClient $platform, string $tenantSlug, Collection $departures, Carbon $now): int
    {
        // All ScrapedDeparture rows for a tenant came from the same company,
        // but we only stored the slug — look the company id up once here.
        $company = collect($platform->companies())->firstWhere('slug', $tenantSlug);

        if ($company === null) {
            $this->error("Tenant {$tenantSlug}: company not found via apex, skipping.");

            return 0;
        }

        $credentials = $platform->revealOaclCredentials($company['id']);

        if ($credentials === null || empty($credentials['tenant_username']) || empty($credentials['tenant_password'])) {
            $this->error("Tenant {$tenantSlug}: missing System A or tenant-user credentials, skipping.");

            return 0;
        }

        $beforeMinutes = $credentials['before_minutes'] ?? self::DEFAULT_BEFORE_MINUTES;
        $afterMinutes = $credentials['after_minutes'] ?? self::DEFAULT_AFTER_MINUTES;

        $due = $this->dueDepartures($departures, $now, $beforeMinutes, $afterMinutes);

        if ($due->isEmpty()) {
            return 0;
        }

        $this->info("Tenant {$tenantSlug}: processing {$due->count()} due departure slot(s) (before={$beforeMinutes}m, after={$afterMinutes}m).");

        $uploader = new PassengerListUploadClient(
            tenantBaseUrl: "{$tenantSlug}.xas.co.tz",
            username: $credentials['tenant_username'],
            password: $credentials['tenant_password'],
        );

        $scraper = new OaclPassengerListScraper(
            baseUrl: config('systema.base_url'),
            username: $credentials['username'],
            password: $credentials['password'],
            headless: (bool) config('systema.headless'),
        );

        try {
            $scraper->start();
            $scraper->login();

            // Group by route+date so we only search OACL once per unique
            // combination, even if several buses/slots on that route are due.
            foreach ($due->groupBy(fn ($item) => $item['departure']->origin . '|' . $item['departure']->destination . '|' . $item['departure']->travel_date) as $routeItems) {
                $sample = $routeItems->first()['departure'];

                try {
                    $scraper->searchRoute($sample->origin, $sample->destination, $sample->travel_date);
                } catch (Throwable $e) {
                    $this->error("  {$sample->origin} -> {$sample->destination}: search failed — {$e->getMessage()}");

                    continue;
                }

                foreach ($routeItems as $item) {
                    $this->processSlot($scraper, $uploader, $item['departure'], $item['slot']);
                }
            }
        } finally {
            $scraper->stop();
        }

        return $due->count();
    }

    protected function processSlot(
        OaclPassengerListScraper $scraper,
        PassengerListUploadClient $uploader,
        ScrapedDeparture $departure,
        string $slot,
    ): void {
        $statusField = "upload_{$slot}_status";
        $attemptedAtField = "upload_{$slot}_attempted_at";

        try {
            $pdf = $scraper->downloadPassengerListPdfByBusId($departure->oacl_bus_id);

            if ($pdf === null) {
                $this->warn("  {$departure->bus_name} @ {$departure->departure_time} ({$slot}): could not retrieve PDF.");
                $departure->update([$statusField => ScrapedDeparture::STATUS_FAILED, $attemptedAtField => now()]);

                return;
            }

            $result = $uploader->upload(
                $pdf,
                "passenger-list-{$departure->oacl_bus_id}-{$slot}.pdf",
                $departure->departure_time,
                from: $departure->origin,
                to: $departure->destination,
            );

            if ($result['success'] ?? false) {
                $this->info("  {$departure->bus_name} @ {$departure->departure_time} ({$slot}): imported ({$result['imported']} passengers).");
                $departure->update([$statusField => ScrapedDeparture::STATUS_SUCCESS, $attemptedAtField => now()]);
            } else {
                $this->warn("  {$departure->bus_name} @ {$departure->departure_time} ({$slot}): import failed — " . ($result['message'] ?? 'unknown error'));
                $departure->update([$statusField => ScrapedDeparture::STATUS_FAILED, $attemptedAtField => now()]);
            }
        } catch (Throwable $e) {
            $this->error("  {$departure->bus_name} @ {$departure->departure_time} ({$slot}): exception — {$e->getMessage()}");
            $departure->update([$statusField => ScrapedDeparture::STATUS_FAILED, $attemptedAtField => now()]);
        }
    }
}
