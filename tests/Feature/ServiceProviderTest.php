<?php

use Illuminate\Support\Facades\Route;
use KoreUi\Calendar\KoreCalendarServiceProvider;

it('fusiona su configuración bajo kore-calendar', function () {
    expect(config('kore-calendar.time.slot_duration'))->toBe('30m');
});

it('sirve los componentes con el prefijo kore sin tapar los de kore-ui', function () {
    // El riesgo de compartir prefijo: que la segunda ruta anónima sustituya a la
    // de kore-ui en vez de sumarse. Si pasara, <x-kore::badge> dejaría de existir.
    $this->blade('<x-kore::badge label="Sigue vivo" />')->assertSee('Sigue vivo');

    $this->blade('<x-kore::calendar.spike-week :columns="[\'lun\']" :events="[]" />')
        ->assertSee('KoreCalendarDrag', false);
});

it('registra la ruta del bundle', function () {
    expect(Route::has('kore-calendar.scripts'))->toBeTrue();
});

it('la URL del bundle lleva huella para poder cachearlo como immutable', function () {
    expect(KoreCalendarServiceProvider::scriptUrl())->toMatch('#/vendor/kore-calendar/kore-calendar\.js\?id=[a-z0-9]+$#');
});

it('sirve el bundle versionado como javascript', function () {
    $this->get(KoreCalendarServiceProvider::scriptUrl())
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
});

it('sin huella se sirve revalidando', function () {
    $this->get('/vendor/kore-calendar/kore-calendar.js')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public');
});
