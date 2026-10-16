<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Tests\Feature\Fixtures\PlainCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\TeamCalendar;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
    TeamCalendar::$asked = [];
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('pinta la vista por defecto con los eventos de events()', function () {
    Livewire::test(TeamCalendar::class)
        ->assertSee('Octubre 2026')
        ->assertSee('Congreso')
        ->assertSeeHtml('role="table"');
});

it('pide a events() el rango dibujado, en instantes de la zona de la vista', function () {
    Livewire::test(TeamCalendar::class);

    // Octubre de 2026 en lunes: del 28-sep al 9-nov (6 semanas), hora de Madrid.
    expect(TeamCalendar::$asked[0])->toBe(['2026-09-28T00:00:00+02:00', '2026-11-09T00:00:00+01:00']);
});

it('navega con previous, next y today, y lo deja en la URL', function () {
    Livewire::test(TeamCalendar::class)
        ->call('next')
        ->assertSet('date', '2026-11-01')
        ->assertSee('Noviembre 2026')
        ->call('previous')->call('previous')
        ->assertSet('date', '2026-09-01')
        ->call('today')
        ->assertSet('date', '')
        ->assertSee('Octubre 2026');
});

it('cambia de vista solo a las que ofrece', function () {
    Livewire::test(TeamCalendar::class)
        ->call('changeView', 'week')
        ->assertSet('view', 'week')
        ->assertSee('Revisión trimestral')
        ->call('changeView', 'day')        // no está en views()
        ->assertSet('view', 'week')
        ->call('changeView', '<script>')
        ->assertSet('view', 'week');
});

it('lee la vista y la fecha de la URL, e ignora valores que no son suyos', function () {
    Livewire::withQueryParams(['view' => 'list', 'date' => '2026-10-14'])
        ->test(TeamCalendar::class)
        ->assertSee('Revisión trimestral')
        ->assertDontSeeHtml('role="table"');

    Livewire::withQueryParams(['view' => 'inventada'])
        ->test(TeamCalendar::class)
        ->assertSee('Octubre 2026');
});

it('goToDate solo acepta fechas reales', function () {
    Livewire::test(TeamCalendar::class)
        ->call('goToDate', '2026-02-30')->assertSet('date', '')
        ->call('goToDate', 'mañana')->assertSet('date', '')
        ->call('goToDate', '2026-12-25')->assertSet('date', '2026-12-25');
});

it('la configuración de configure() sobrevive a las acciones', function () {
    // configure() corre en booted(): si corriera solo en mount, la segunda
    // petición perdería el eje de 08:00 a 20:00.
    Livewire::test(TeamCalendar::class)
        ->call('changeView', 'week')
        ->call('next')
        ->assertSeeHtml('>8:00<')
        ->assertDontSeeHtml('>7:00<');
});

it('ofrece las vistas por recurso solo si hay recursos', function () {
    Livewire::test(TeamCalendar::class)->assertSee('Recursos · día');
    Livewire::test(PlainCalendar::class)->assertDontSee('Recursos · día')->assertSee('Semana');
});

it('la vista por recurso pone cada evento en su sala', function () {
    Livewire::withQueryParams(['view' => 'resource-day', 'date' => '2026-10-14'])
        ->test(TeamCalendar::class)
        ->assertSee('Sala Ámbar')
        ->assertSee('Revisión trimestral');
});

it('la barra usa acciones de Livewire, no enlaces', function () {
    Livewire::test(TeamCalendar::class)
        ->assertSeeHtml('wire:click="next"')
        ->assertSeeHtml('wire:click="changeView(\'week\')"')
        ->assertSeeHtml('aria-live="polite"');
});
