<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Tests\Feature\Fixtures\TeamCalendar;
use Livewire\Livewire;

/*
| `?date=` y `$wire.date` son texto que escribe cualquiera. Solo una fecha real (YYYY-MM-DD) dentro
| de lo que admite el calendario se usa; lo demás se ignora y sale «hoy». Antes `foo` y `10000-01-01`
| daban un 500, y `+999999 years` o `@999999999999` llegaban a Carbon y pintaban fechas absurdas.
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('una fecha de la URL que no vale se ignora y el calendario sale en hoy', function (string $date) {
    Livewire::withQueryParams(['date' => $date])
        ->test(TeamCalendar::class)
        ->assertSee('Octubre 2026')
        ->assertSee('Congreso');
})->with([
    'texto' => ['foo'],
    'etiqueta' => ['<script>alert(1)</script>'],
    'día imposible' => ['2026-13-45'],
    '31 de febrero' => ['2026-02-31'],
    'año cero' => ['0000-00-00'],
    'año 1' => ['0001-01-01'],
    'año 9999' => ['9999-12-31'],
    'año de cinco cifras' => ['10000-01-01'],
    'relativa' => ['+999999 years'],
    'timestamp' => ['@999999999999'],
    'con hora' => ['2026-12-25 10:00:00'],
    'con zona absurda' => ['2026-10-16T10:00:00+99:99'],
    'negativa' => ['-0001-01-01'],
    'larguísima' => [str_repeat('2', 100_000)],
    'científica' => ['1e9'],
]);

it('una fecha válida de la URL sigue funcionando', function () {
    Livewire::withQueryParams(['date' => '2026-12-25'])
        ->test(TeamCalendar::class)
        ->assertSee('Diciembre 2026');
});

it('una lista en la URL tampoco rompe nada', function () {
    Livewire::withQueryParams(['date' => ['2026-12-25']])
        ->test(TeamCalendar::class)
        ->assertSee('Octubre 2026');
});

it('lo que el navegador escribe en la propiedad date se trata igual', function (string $date) {
    Livewire::test(TeamCalendar::class)
        ->set('date', $date)
        ->assertSee('Octubre 2026')
        // Y las acciones siguientes siguen partiendo de «hoy».
        ->call('next')
        ->assertSet('date', '2026-11-01');
})->with(['texto' => ['foo'], 'relativa' => ['+9999999 years'], 'año 9999' => ['9999-12-31'], 'timestamp' => ['@999999999999']]);

it('goToDate ignora lo que no es una fecha real dentro de la ventana', function (string $date) {
    Livewire::test(TeamCalendar::class)
        ->call('goToDate', $date)
        ->assertSet('date', '');
})->with([
    'texto' => ['mañana'],
    '30 de febrero' => ['2026-02-30'],
    'año 9999' => ['9999-12-31'],
    'año 1' => ['0001-01-01'],
    'fuera por arriba' => ['2227-01-01'],
    'fuera por abajo' => ['1825-12-31'],
    'con salto de línea' => ["2026-12-25\n"],
    'relativa' => ['+1 year'],
]);

it('goToDate acepta los bordes de la ventana', function () {
    Livewire::test(TeamCalendar::class)
        ->call('goToDate', '1826-01-01')->assertSet('date', '1826-01-01')
        ->call('goToDate', '2226-12-31')->assertSet('date', '2226-12-31');
});

it('next no se sale de la ventana: se queda en el último mes en vez de caer en hoy', function () {
    Livewire::test(TeamCalendar::class)
        ->call('goToDate', '2226-12-15')
        ->call('next')
        ->assertSet('date', '2226-12-15')
        ->assertSee('Diciembre 2226');
});

it('valid_range amplía la fecha que se acepta', function () {
    Livewire::withQueryParams(['date' => '1600-03-01'])
        ->test(TeamCalendar::class)
        ->assertSee('Octubre 2026');

    Livewire::withQueryParams(['date' => '1600-03-01'])
        ->test(\KoreUi\Calendar\Tests\Feature\Fixtures\HistoricCalendar::class)
        ->assertSee('Marzo 1600');
});
