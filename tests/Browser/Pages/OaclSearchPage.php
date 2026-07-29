<?php

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class OaclSearchPage extends Page
{
    public function url(): string
    {
        return '/index.php';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertPresent('#stn');
    }

    /**
     * Type into a jQuery UI autocomplete field and click the matching suggestion.
     * Suggestions render as <li><div class="ui-menu-item-wrapper">TEXT</div></li>
     * with no <a> tag, so we match by text and click via JS rather than
     * relying on Dusk's clickLink(). There are TWO .ui-autocomplete menus on
     * this page (one per field, both always present in the DOM, linked via
     * the jQuery UI widget instance's own menu element — no HTML attribute
     * reliably links input to menu here) — querying ".ui-autocomplete"
     * without scoping to the right one grabs whichever renders first in DOM
     * order, which silently reads/clicks the wrong field's stale menu.
     */
    public function selectAutocomplete(Browser $browser, string $fieldSelector, string $value): void
    {
        $browser->click($fieldSelector);
        $browser->driver->executeScript("document.querySelector(arguments[0]).value = '';", [$fieldSelector]);
        $browser->type($fieldSelector, $value);

        $menuId = $browser->driver->executeScript(<<<'JS'
            const widget = jQuery(document.querySelector(arguments[0])).data('uiAutocomplete');
            return widget && widget.menu && widget.menu.element ? widget.menu.element[0].id : null;
        JS, [$fieldSelector]);

        $browser->driver->wait(10, 250)->until(function () use ($browser, $menuId) {
            return $browser->driver->executeScript(<<<'JS'
                const menu = document.getElementById(arguments[0]);
                return !!menu && menu.style.display !== 'none' && menu.querySelectorAll('li.ui-menu-item').length > 0;
            JS, [$menuId]);
        });

        $clicked = $browser->driver->executeScript(<<<'JS'
            const needle = arguments[1].toLowerCase();
            const menu = document.getElementById(arguments[0]);
            if (!menu) return false;
            const items = Array.from(menu.querySelectorAll('.ui-menu-item-wrapper'));
            const match = items.find(el => el.textContent.trim().toLowerCase().includes(needle)) || items[0];
            if (!match) return false;
            match.dispatchEvent(new Event('mouseenter', { bubbles: true }));
            match.click();
            return true;
        JS, [$menuId, $value]);

        if (! $clicked) {
            throw new \RuntimeException("No autocomplete suggestion found for \"{$value}\" in {$fieldSelector}.");
        }

        $browser->pause(500);
    }

    public function setTravelDate(Browser $browser, string $dateDisplay): void
    {
        // #trvl_date is a bootstrap-datepicker input; setting .value directly
        // and firing a plain 'change' event does NOT trigger the page's
        // search logic. The widget's own jQuery API + 'changeDate' event is
        // what the page actually listens for to populate the bus list.
        $browser->driver->executeScript(
            "jQuery('#trvl_date').datepicker('setDate', arguments[0]); jQuery('#trvl_date').trigger('changeDate');",
            [$dateDisplay]
        );
    }

    public function search(Browser $browser, string $from, string $to, string $travelDate): void
    {
        // A prior row click (far down a long results table) leaves the page
        // scrolled there; the fixed-top navbar then overlaps #stn on the
        // next search in the same session, intercepting the click.
        $browser->driver->executeScript('window.scrollTo(0, 0);');

        $this->selectAutocomplete($browser, '#stn', $from);
        $this->selectAutocomplete($browser, '#stn_to', $to);

        // The results table is repopulated via AJAX after changeDate fires,
        // passing through an explicit "Loading...." placeholder (a fixed,
        // static string — see markup below) before the real rows land.
        // Waiting for "any change, then stable across two polls" is not
        // enough: the placeholder itself is static, so two consecutive
        // polls both see "Loading...." and the wait exits right there,
        // before the real AJAX response replaces it (seen as spurious
        // 0/2-row reads and "bus_id not found" on routes that do have
        // buses). So we explicitly wait out the placeholder first, then
        // wait for the content to differ from the pre-search snapshot and
        // be stable across two consecutive checks.
        $before = $this->resultsTableHtml($browser);

        $this->setTravelDate($browser, $travelDate);

        $browser->driver->wait(15, 200)->until(
            fn () => ! str_contains($this->resultsTableHtml($browser), 'Loading')
        );

        $previous = null;

        try {
            $browser->driver->wait(15, 300)->until(function () use ($browser, $before, &$previous) {
                $current = $this->resultsTableHtml($browser);

                $settled = $current !== $before
                    && ! str_contains($current, 'Loading')
                    && $current === $previous;
                $previous = $current;

                return $settled;
            });
        } catch (\Facebook\WebDriver\Exception\TimeoutException) {
            // Fell through without ever settling on a changed, stable value
            // (e.g. the new search also legitimately has zero buses and the
            // table never differs from "before") — fall back to a short
            // settle pause rather than failing the whole search.
        }

        $browser->pause(500);
    }

    protected function resultsTableHtml(Browser $browser): string
    {
        return (string) $browser->driver->executeScript(
            "return document.querySelector('#div_show_bues').innerHTML;"
        );
    }

    /**
     * Number of bus rows currently rendered in the results table.
     */
    public function busCount(Browser $browser): int
    {
        return (int) $browser->script("return document.querySelectorAll('#div_show_bues tr[id^=\"tr\"]').length")[0];
    }

    /**
     * Departure time for a bus row, as "HH:MM" (24-hour). The table's 3rd
     * <td> holds "26-07-2026 06:00 AM" / "26-07-2026 19:30 PM" — OACL's
     * own markup mixes 24-hour hours with a trailing AM/PM suffix (e.g.
     * "19:30 PM"), so we take the raw HH:MM and only flip AM/PM when the
     * hour is still in 12-hour range (01-12) to normalize the genuine
     * 12-hour cases without corrupting the already-24-hour ones.
     */
    public function departureTime(Browser $browser, int $rowIndex): ?string
    {
        $cellText = $browser->driver->executeScript(
            'var tr = document.getElementById(arguments[0]); return tr ? tr.children[2].textContent.trim() : null;',
            ['tr' . $rowIndex]
        );

        if ($cellText === null) {
            return null;
        }

        if (! preg_match('/(\d{1,2}):(\d{2})\s*(AM|PM)?/i', $cellText, $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = $m[2];
        $meridiem = strtoupper($m[3] ?? '');

        if ($meridiem === 'PM' && $hour >= 1 && $hour <= 11) {
            $hour += 12;
        } elseif ($meridiem === 'AM' && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%02d:%s', $hour % 24, $minute);
    }

    /**
     * OACL's internal bus id for a row, parsed from the row's
     * onclick="fun_set_bus_info(rowIndex, bus_id, busName)" attribute —
     * this is the stable identity to store and re-match against later,
     * since row position/count can change between searches (buses fill up,
     * new ones get added) while bus_id for a given trip does not.
     */
    public function busIdFor(Browser $browser, int $rowIndex): ?string
    {
        $onclick = $browser->driver->executeScript(
            'var tr = document.getElementById(arguments[0]); return tr ? tr.getAttribute("onclick") : null;',
            ['tr' . $rowIndex]
        );

        if ($onclick === null || ! preg_match("/fun_set_bus_info\('\d+',\s*'(\d+)'/", $onclick, $m)) {
            return null;
        }

        return $m[1];
    }

    public function busNameFor(Browser $browser, int $rowIndex): ?string
    {
        $onclick = $browser->driver->executeScript(
            'var tr = document.getElementById(arguments[0]); return tr ? tr.getAttribute("onclick") : null;',
            ['tr' . $rowIndex]
        );

        if ($onclick === null || ! preg_match("/fun_set_bus_info\('\d+',\s*'\d+',\s*'([^']*)'\)/", $onclick, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * The plate shown directly on the row itself (e.g. "BM COACH ... <br>
     * Plate no. [T 105 EEN]") — the plate this row is actually listed under,
     * as opposed to whichever option the #pltno dropdown defaults to after
     * clicking the row (some buses have multiple plates on file, and the
     * dropdown's default selection is not necessarily this one — uploading
     * with the wrong plate produces a PDF System B rejects as "not
     * registered on this tenant").
     */
    public function rowPlateFor(Browser $browser, int $rowIndex): ?string
    {
        $cellText = $browser->driver->executeScript(
            'var tr = document.getElementById(arguments[0]); return tr ? tr.children[1].textContent : null;',
            ['tr' . $rowIndex]
        );

        if ($cellText === null || ! preg_match('/Plate no\.\s*\[\s*([^\]]+?)\s*\]/i', $cellText, $m)) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * Select the #pltno option matching the row's own displayed plate
     * (see rowPlateFor). Returns false if the dropdown has no option for
     * that plate — the row click still populated #pltno with whatever
     * plates OACL has on file for this bus_id, which may not include the
     * one actually shown on the row.
     */
    public function selectRowPlate(Browser $browser, string $plate): bool
    {
        $selected = $browser->driver->executeScript(<<<'JS'
            const needle = arguments[0].trim().toLowerCase();
            const sel = document.getElementById('pltno');
            if (!sel) return false;
            const option = Array.from(sel.options).find(o => o.textContent.trim().toLowerCase() === needle);
            if (!option) return false;
            sel.value = option.value;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        JS, [$plate]);

        return (bool) $selected;
    }

    /**
     * Find the row index (1-indexed) whose bus_id matches, re-scanning the
     * currently rendered results table — used when re-targeting a specific
     * bus on a fresh search rather than trusting a previously seen row
     * position.
     */
    public function findRowByBusId(Browser $browser, string $busId): ?int
    {
        $count = $this->busCount($browser);

        for ($i = 1; $i <= $count; $i++) {
            if ($this->busIdFor($browser, $i) === $busId) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Click a bus row (1-indexed, matches the tr{n} id scheme) and submit
     * "Get Passenger List". The PDF opens in a NEW browser tab/window (after
     * a JS confirm() dialog), so we switch to it, read its URL, then close
     * it and switch back to the results page for the next row.
     *
     * Before submitting, the #pltno dropdown (populated on row click from
     * that bus's plts_N list, may contain multiple plates for one bus_id) is
     * forced to match the plate actually displayed on the row — OACL
     * defaults it to whichever plate is first in that list, which is not
     * necessarily the one shown, and generates the PDF using whatever is
     * currently selected. Uploading a PDF generated against the wrong plate
     * fails System B's import with "not registered on this tenant".
     *
     * @throws \RuntimeException if the row's displayed plate has no
     *   matching #pltno option — the caller should treat this the same as
     *   System B's "not registered" rejection rather than attempt upload.
     */
    public function fetchPassengerListUrl(Browser $browser, int $rowIndex): string
    {
        $driver = $browser->driver;
        $originalWindow = $driver->getWindowHandle();

        $browser->click('#tr' . $rowIndex)->pause(300);

        $plate = $this->rowPlateFor($browser, $rowIndex);

        if ($plate !== null && ! $this->selectRowPlate($browser, $plate)) {
            throw new \RuntimeException("Row {$rowIndex}: no plate-dropdown option matches the row's displayed plate \"{$plate}\" — bus is not registered under this plate.");
        }

        $browser->click('#btn_save')
            ->waitForDialog(5)
            ->acceptDialog()
            ->pause(1000);

        $newWindow = collect($driver->getWindowHandles())
            ->first(fn ($handle) => $handle !== $originalWindow);

        if ($newWindow === null) {
            return $driver->getCurrentURL();
        }

        $driver->switchTo()->window($newWindow);

        $driver->wait(10, 250)->until(function () use ($driver) {
            $url = $driver->getCurrentURL();

            return $url !== 'about:blank' && str_starts_with($url, 'http');
        });

        $url = $driver->getCurrentURL();
        $driver->close();
        $driver->switchTo()->window($originalWindow);

        return $url;
    }

    public function elements(): array
    {
        return [
            '@from' => '#stn',
            '@to' => '#stn_to',
            '@travel_date' => '#trvl_date',
            '@results_body' => '#div_show_bues',
            '@get_passenger_list' => '#btn_save',
        ];
    }
}
