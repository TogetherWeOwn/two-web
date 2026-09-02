<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }

    /**
     * Screenshot every test on completion, pass or fail (TOG-782). Dusk's own
     * ProvidesBrowser::captureFailuresFor() only fires from browse()'s catch
     * blocks, so there is no declarative always-capture mode to flip — this
     * covers the pass path it can't reach. Both can fire on a failing test;
     * that's a harmless, mildly redundant screenshot, not a conflict.
     */
    protected function tearDown(): void
    {
        $browsers = collect(static::$browsers);

        if ($browsers->isNotEmpty()) {
            $outcome = $this->status()->isSuccess() ? 'pass' : 'fail';
            $name = str_replace('\\', '_', static::class).'__'.$this->name().'__'.$outcome;

            $browsers->each(function ($browser, $key) use ($name) {
                $browser->screenshot($name.'-'.$key);
            });
        }

        parent::tearDown();
    }
}
