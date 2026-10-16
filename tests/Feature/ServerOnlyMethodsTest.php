<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\KoreCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\PlainLivewire;
use KoreUi\Calendar\Tests\Feature\Fixtures\ProtectedCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\PublicHooksCalendar as Cal;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

/*
| Livewire deja llamar desde el navegador a cualquier método PÚBLICO de la subclase.
| `events()`, `resources()`, `configure()` y los hooks de guardado son del servidor:
| el paquete los rechaza como si no existieran aunque el consumidor los declare públicos
| (los docs de antes lo enseñaban así), y pide `protected` para no depender de eso.
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
    Cal::$log = [];
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lista exactamente los métodos del consumidor', function () {
    expect(KoreCalendar::SERVER_ONLY)->toEqualCanonicalizing([
        'events', 'resources', 'configure', 'booted',
        'persistMove', 'persistResize', 'createEvent', 'updateEvent', 'deleteEvent',
        'onDateSelect', 'onEventClick', 'conflicts',
    ]);
});

it('los métodos del consumidor no se pueden llamar desde el navegador aunque estén declarados públicos', function (string $metodo) {
    $t = Livewire::test(Cal::class);
    Cal::$log = [];

    expect(fn () => $t->call($metodo))->toThrow(MethodNotFoundException::class)
        // Y ni siquiera llegó a ejecutarse.
        ->and(Cal::$log)->toBe([]);
})->with(KoreCalendar::SERVER_ONLY);

it('con los argumentos que mandaría un atacante tampoco', function () {
    $t = Livewire::test(Cal::class);
    Cal::$log = [];

    expect(fn () => $t->call('events', '2020-01-01', '2030-12-31'))->toThrow(MethodNotFoundException::class)
        ->and(fn () => $t->call('createEvent', ['title' => 'x']))->toThrow(MethodNotFoundException::class)
        ->and(fn () => $t->call('deleteEvent', ['id' => 1]))->toThrow(MethodNotFoundException::class)
        ->and(Cal::$log)->toBe([]);
});

it('da igual cómo se escriba el nombre: PHP resuelve los métodos sin mirar mayúsculas', function (string $metodo) {
    expect(fn () => Livewire::test(Cal::class)->call($metodo))->toThrow(MethodNotFoundException::class);
})->with(['EVENTS', 'Events', 'PersistMove', 'CREATEEVENT']);

it('el control: otro método público del consumidor sí se puede llamar', function () {
    $t = Livewire::test(Cal::class)->call('ping');

    expect($t->effects['returns'] ?? null)->toBe(['pong']);
});

it('las acciones del calendario siguen funcionando', function () {
    $t = Livewire::test(Cal::class);

    $t->call('next')->assertSet('date', '2026-10-19')
        ->call('previous')->assertSet('date', '2026-10-12')
        ->call('today')->assertSet('date', '')
        ->call('changeView', 'month')->assertSet('view', 'month')
        ->call('goToDate', '2026-11-02')->assertSet('date', '2026-11-02')
        ->call('clickEvent', 1);

    // Con una semana donde está el evento, mover pasa por la validación y llega al hook del consumidor.
    $t = Livewire::test(Cal::class)->call('changeView', 'week')->call('goToDate', '2026-10-14');
    $t->call('moveEvent', 1, \KoreUi\Calendar\Time\Marker::parse('2026-10-14 12:00:00')->ts, false);

    expect($t->effects['returns'][0] ?? null)->toMatchArray(['ok' => true])
        ->and(Cal::$log)->toContain('persistMove');
});

it('con los métodos protected el calendario pinta y navega igual', function () {
    $t = Livewire::test(ProtectedCalendar::class)
        ->assertSee('Revisión')
        ->call('next')->assertSet('date', '2026-10-19');

    expect($t->html())->not->toContain('ASISTENTES-PRIVADOS');
});

it('con protected, Livewire ya no los ofrece: tampoco hace falta el bloqueo del paquete', function (string $metodo) {
    expect(fn () => Livewire::test(ProtectedCalendar::class)->call($metodo))->toThrow(MethodNotFoundException::class);
})->with(['events', 'resources', 'configure']);

it('un componente de Livewire que no es un calendario no se toca: sus propios events() y configure() siguen siendo suyos', function () {
    $t = Livewire::test(PlainLivewire::class)->call('events');

    expect($t->effects['returns'] ?? null)->toBe(['mis eventos']);

    $t->call('configure');

    expect($t->effects['returns'] ?? null)->toBe(['mi configuración']);
});
