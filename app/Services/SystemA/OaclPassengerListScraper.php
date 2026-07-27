<?php

namespace App\Services\SystemA;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Dusk\Browser;
use Tests\Browser\Pages\OaclLoginPage;
use Tests\Browser\Pages\OaclSearchPage;

class OaclPassengerListScraper
{
    protected ?RemoteWebDriver $driver = null;

    protected ?Browser $browser = null;

    public function __construct(
        protected string $baseUrl,
        protected string $username,
        protected string $password,
        protected bool $headless = true,
    ) {}

    public function start(): void
    {
        // Chrome falls back to $HOME (e.g. ~/.local/share/...) for its
        // profile/cache dirs. Service users like www-data commonly have a
        // $HOME that isn't writable by them, so Chrome can't create its
        // profile dir and exits immediately ("Trace/breakpoint trap") the
        // moment ChromeDriver launches it as that user — even though the
        // same binary launches fine interactively as a normal login user.
        // A unique, definitely-writable --user-data-dir avoids this
        // regardless of which user/environment the process runs under.
        $userDataDir = sys_get_temp_dir() . '/oacl-chrome-' . getmypid() . '-' . bin2hex(random_bytes(4));


        $options = (new ChromeOptions)
    ->setBinary('/usr/bin/google-chrome')
    ->addArguments([
        '--headless=new',
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--window-size=1920,1080',
    ]);

        $this->driver = RemoteWebDriver::create(
            config('systema.driver_url', 'http://localhost:9515'),
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options)
        );

        $this->browser = new Browser($this->driver);
        Browser::$baseUrl = rtrim($this->baseUrl, '/');
    }

    public function stop(): void
    {
        $this->driver?->quit();
    }

    public function login(): void
    {
        $page = new OaclLoginPage;
        $this->browser->visit($page);
        $page->login($this->browser, $this->username, $this->password);

        // Home page's nav links carry a session-specific ?key=... token, so
        // we read the real href via JS and navigate directly rather than
        // guessing the URL or relying on a fragile clickLink().
        $href = $this->driver->executeScript(
            'var a = Array.from(document.querySelectorAll("a")).find(a => a.textContent.trim() === "Passenger List"); return a ? a.getAttribute("href") : null;'
        );

        if ($href === null) {
            throw new \RuntimeException('Could not find "Passenger List" nav link after login.');
        }

        $this->driver->get(rtrim($this->baseUrl, '/') . '/' . ltrim($href, '/'));
        $this->browser->pause(1000);
    }

    /**
     * Search a route and return the raw <tr> ids found (e.g. ["tr1", "tr2", ...]).
     */
    public function searchRoute(string $from, string $to, string $travelDate): array
    {
        $page = new OaclSearchPage;
        $page->search($this->browser, $from, $to, $travelDate);

        $count = $page->busCount($this->browser);

        return range(1, $count);
    }

    /**
     * Departure time for a bus row, as "HH:MM" (24-hour), read straight from
     * the results table rather than left for System B to guess/default.
     */
    public function departureTimeFor(int $rowIndex): ?string
    {
        return (new OaclSearchPage)->departureTime($this->browser, $rowIndex);
    }

    public function busIdFor(int $rowIndex): ?string
    {
        return (new OaclSearchPage)->busIdFor($this->browser, $rowIndex);
    }

    public function busNameFor(int $rowIndex): ?string
    {
        return (new OaclSearchPage)->busNameFor($this->browser, $rowIndex);
    }

    /**
     * Re-find a specific bus by its stable OACL bus_id within the current
     * search results, then download its passenger list. Used by the
     * watcher, which searches fresh at upload time rather than trusting a
     * row index/count captured during an earlier discovery run.
     */
    public function downloadPassengerListPdfByBusId(string $busId): ?string
    {
        $page = new OaclSearchPage;
        $rowIndex = $page->findRowByBusId($this->browser, $busId);

        if ($rowIndex === null) {
            Log::warning('OACL scraper: bus_id not found in current search results', ['bus_id' => $busId]);

            return null;
        }

        return $this->downloadPassengerListPdf($rowIndex);
    }

    /**
     * Click bus row $rowIndex, trigger "Get Passenger List", and immediately
     * fetch the resulting PDF over HTTP using the browser's session cookies
     * (the key/hkey tokens in that URL are single-use / short-lived, so this
     * must happen right after the click — never batch URLs for later).
     */
    public function downloadPassengerListPdf(int $rowIndex): ?string
    {
        $page = new OaclSearchPage;
        $pdfUrl = $page->fetchPassengerListUrl($this->browser, $rowIndex);

        if (! str_contains($pdfUrl, 'get_passenger_list.php')) {
            Log::warning('OACL scraper: unexpected navigation URL after Get Passenger List', [
                'row' => $rowIndex,
                'url' => $pdfUrl,
            ]);

            return null;
        }

        $cookieJar = collect($this->driver->manage()->getCookies())
            ->mapWithKeys(fn ($cookie) => [$cookie->getName() => $cookie->getValue()])
            ->all();

        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->withCookies($cookieJar, parse_url($this->baseUrl, PHP_URL_HOST))
            ->get($pdfUrl);

        if (! $response->successful() || ! str_starts_with($response->body(), '%PDF-')) {
            Log::warning('OACL scraper: passenger list fetch failed or token expired', [
                'row' => $rowIndex,
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
            ]);

            return null;
        }

        return $response->body();
    }
}
