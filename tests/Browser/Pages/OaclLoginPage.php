<?php

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class OaclLoginPage extends Page
{
    public function url(): string
    {
        return '/index.php';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertSee('Sign in');
    }

    public function login(Browser $browser, string $username, string $password): void
    {
        $browser->type('#uname', $username)
            ->type('#pswd', $password)
            ->click('button[type="submit"]')
            ->pause(1500);
    }

    public function elements(): array
    {
        return [
            '@username' => '#uname',
            '@password' => '#pswd',
            '@submit' => 'button[type="submit"]',
        ];
    }
}
