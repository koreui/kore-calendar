<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventSource;
use KoreUi\Calendar\Events\Sources\ArraySource;
use KoreUi\Calendar\Events\Sources\CallbackSource;

$rango = fn () => [CarbonImmutable::parse('2026-10-12 00:00', 'Europe/Madrid'), CarbonImmutable::parse('2026-10-19 00:00', 'Europe/Madrid')];

// ── ArraySource ──────────────────────────────────────────────────────

it('ArraySource devuelve lo que se le dio, eventos y arrays sin tocar', function () use ($rango) {
    [$from, $to] = $rango();
    $evento = CalendarEvent::make(id: 1, title: 'a', start: '2026-10-13');
    $array = ['id' => 2, 'title' => 'b', 'start' => '2026-10-14'];

    $source = new ArraySource([$evento, $array]);

    expect($source)->toBeInstanceOf(EventSource::class)
        ->and($source->events($from, $to))->toBe([$evento, $array]);
});

it('ArraySource no filtra por rango: eso lo hace el colector, que sabe normalizar', function () use ($rango) {
    [$from, $to] = $rango();
    $lejano = CalendarEvent::make(id: 1, title: 'a', start: '2030-01-01');

    expect((new ArraySource([$lejano]))->events($from, $to))->toBe([$lejano]);
});

it('ArraySource reindexa y se puede consultar muchas veces aunque venga de un generador', function () use ($rango) {
    [$from, $to] = $rango();
    $a = CalendarEvent::make(id: 1, title: 'a', start: '2026-10-13');
    $b = CalendarEvent::make(id: 2, title: 'b', start: '2026-10-14');

    $source = new ArraySource((function () use ($a, $b) {
        yield 'x' => $a;
        yield 'x' => $b;   // misma clave: un array la habría pisado
    })());

    expect($source->events($from, $to))->toBe([$a, $b])
        ->and($source->events($from, $to))->toBe([$a, $b]);
});

it('ArraySource vacía devuelve una lista vacía', function () use ($rango) {
    [$from, $to] = $rango();

    expect((new ArraySource([]))->events($from, $to))->toBe([]);
});

// ── CallbackSource ───────────────────────────────────────────────────

it('CallbackSource llama al closure con el rango recibido', function () use ($rango) {
    [$from, $to] = $rango();
    $visto = [];

    $source = new CallbackSource(function (CarbonImmutable $a, CarbonImmutable $b) use (&$visto) {
        $visto = [$a, $b];

        return [['id' => 1, 'title' => 'x', 'start' => '2026-10-13']];
    });

    expect($source)->toBeInstanceOf(EventSource::class)
        ->and($source->events($from, $to))->toBe([['id' => 1, 'title' => 'x', 'start' => '2026-10-13']])
        ->and($visto[0])->toBe($from)
        ->and($visto[1])->toBe($to);
});

it('CallbackSource se vuelve a llamar en cada consulta', function () use ($rango) {
    [$from, $to] = $rango();
    $llamadas = 0;

    $source = new CallbackSource(function () use (&$llamadas) {
        $llamadas++;

        return [];
    });

    $source->events($from, $to);
    $source->events($from, $to);

    expect($llamadas)->toBe(2);
});

it('CallbackSource admite generadores y colecciones', function () use ($rango) {
    [$from, $to] = $rango();

    $generador = new CallbackSource(function () {
        yield CalendarEvent::make(id: 1, title: 'g', start: '2026-10-13');
    });
    $coleccion = new CallbackSource(fn () => collect([CalendarEvent::make(id: 2, title: 'c', start: '2026-10-13')]));

    expect(iterator_to_array($generador->events($from, $to), false))->toHaveCount(1)
        ->and(iterator_to_array($coleccion->events($from, $to), false))->toHaveCount(1);
});

it('CallbackSource que no devuelve un iterable lanza con el tipo recibido (un return olvidado)', function (mixed $valor, string $tipo) use ($rango) {
    [$from, $to] = $rango();
    $source = new CallbackSource(fn () => $valor);

    expect(fn () => $source->events($from, $to))
        ->toThrow(UnexpectedValueException::class, "debe devolver un iterable de eventos (array, Collection o generador) y devolvió {$tipo}");
})->with([
    'null' => [null, 'null'],
    'string' => ['no', 'string'],
    'un evento suelto' => [CalendarEvent::make(id: 1, title: 'x', start: '2026-10-13'), CalendarEvent::class],
]);
