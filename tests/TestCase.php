<?php

namespace KoreUi\Calendar\Tests;

use BladeUI\Icons\BladeIconsServiceProvider;
use KoreUi\Calendar\KoreCalendarServiceProvider;
use KoreUi\KoreUiServiceProvider;
use Livewire\LivewireServiceProvider;
use MallardDuck\LucideIcons\BladeLucideIconsServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeLucideIconsServiceProvider::class,
            KoreUiServiceProvider::class,
            KoreCalendarServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
