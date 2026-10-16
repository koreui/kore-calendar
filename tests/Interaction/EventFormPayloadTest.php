<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Interaction\EventFormPayload;
use KoreUi\Calendar\Resources\ResourceSet;

require_once __DIR__.'/helpers.php';

/*
| Lo que devuelve el formulario, vuelto a comprobar. Los casos mal formados también
| están, uno a uno, en tests/Feature/EventFormFlowTest.php (lo que llega al hook);
| aquí se mira el contrato de la función: qué devuelve y por qué.
*/

beforeEach(fn () => app()->setLocale('es'));

function epRaw(array $over = []): array
{
    return [
        'title' => 'Reunión', 'allDay' => false,
        'start' => '2026-10-15 14:00:00', 'end' => '2026-10-15 15:00:00',
        'resourceId' => null, 'color' => null,
        ...$over,
    ];
}

function epCheck(array $over = [], ?CalendarEvent $original = null, array $settings = [], array $resources = []): array|string
{
    return EventFormPayload::check(epRaw($over), ixSettings($settings), new ResourceSet($resources), $original);
}

it('devuelve los datos limpios, con los instantes en la zona del calendario', function () {
    $datos = epCheck(['title' => '  Reunión  ', 'color' => 'chart-3']);

    expect($datos)->toMatchArray([
        'mode' => 'create', 'id' => null, 'title' => 'Reunión', 'allDay' => false,
        'start' => '2026-10-15 14:00:00', 'end' => '2026-10-15 15:00:00',
        'resourceId' => null, 'resourceIds' => [], 'color' => 'chart-3',
    ])
        ->and($datos['startAt'])->toBeInstanceOf(CarbonImmutable::class)
        ->and(ixIso($datos['startAt']))->toBe('2026-10-15T14:00:00+02:00')
        ->and(ixIso($datos['endAt']))->toBe('2026-10-15T15:00:00+02:00');
});

it('el modo y el id salen del evento que se edita, no de lo que mande el navegador', function () {
    $original = CalendarEvent::make(id: 'ev-7', title: 'Antes', start: '2026-10-14 10:00');

    $datos = epCheck(['mode' => 'create', 'id' => 999], $original);

    expect($datos)->toMatchArray(['mode' => 'edit', 'id' => 'ev-7']);
});

it('un fin vacío es lo mismo que ningún fin', function () {
    expect(epCheck(['end' => null]))->toMatchArray(['end' => null, 'endAt' => null])
        ->and(epCheck(['end' => '']))->toMatchArray(['end' => null, 'endAt' => null])
        ->and(epCheck()['end'])->toBe('2026-10-15 15:00:00');

    // Y sin clave.
    $raw = epRaw();
    unset($raw['end']);
    expect(EventFormPayload::check($raw, ixSettings(), new ResourceSet([])))->toMatchArray(['end' => null]);
});

it('un día completo: fechas sin hora, el fin tiene que ser el día siguiente o más', function () {
    expect(epCheck(['allDay' => true, 'start' => '2026-10-15', 'end' => '2026-10-16']))
        ->toMatchArray(['allDay' => true, 'start' => '2026-10-15', 'end' => '2026-10-16'])
        ->and(epCheck(['allDay' => true, 'start' => '2026-10-15', 'end' => '2026-10-15']))->toBeString()
        ->and(epCheck(['allDay' => true, 'start' => '2026-10-15 00:00:00', 'end' => null]))->toBeString();
});

it('un evento con hora puede acabar justo cuando empieza: el normalizador le pondrá la duración por defecto', function () {
    expect(epCheck(['end' => '2026-10-15 14:00:00']))->toBeArray()
        ->and(epCheck(['end' => '2026-10-15 13:59:59']))->toBeString();
});

it('el color vacío es ninguno, uno de la lista vale y uno suyo solo si ya lo tenía el evento', function () {
    $conColor = CalendarEvent::make(id: 1, title: 'x', start: '2026-10-14 10:00', color: 'rebeccapurple');

    expect(epCheck(['color' => ''])['color'])->toBeNull()
        ->and(epCheck(['color' => 'destructive'])['color'])->toBe('destructive')
        ->and(epCheck(['color' => 'rebeccapurple']))->toBeString()
        ->and(epCheck(['color' => 'rebeccapurple'], $conColor)['color'])->toBe('rebeccapurple')
        ->and(epCheck(['color' => 'red; background: url(x)'], $conColor))->toBeString();
});

it('la lista de colores y sus nombres traducidos coinciden', function () {
    foreach (EventFormPayload::COLORS as $token => $clave) {
        expect(config("kore-calendar.translations.{$clave}"))->toBeString()->not->toBeEmpty("falta la traducción {$clave} del color {$token}");
    }

    expect(array_keys(EventFormPayload::COLORS))->toBe([
        'primary', 'success', 'warning', 'destructive', 'info',
        'chart-1', 'chart-2', 'chart-3', 'chart-4', 'chart-5', 'chart-6', 'chart-7', 'chart-8',
    ]);
});

it('el recurso tiene que existir; un id numérico y su texto son el mismo', function () {
    $recursos = [['id' => 10, 'title' => 'Sala A']];

    expect(epCheck(['resourceId' => '10'], resources: $recursos))->toMatchArray(['resourceId' => 10, 'resourceIds' => [10]])
        ->and(epCheck(['resourceId' => 10], resources: $recursos)['resourceId'])->toBe(10)
        ->and(epCheck(['resourceId' => '11'], resources: $recursos))->toBe('El recurso de destino no existe')
        ->and(epCheck(['resourceId' => ''], resources: $recursos)['resourceId'])->toBeNull();
});

it('el rango válido aplica al evento entero, también al que no trae fin', function () {
    $rango = ['interaction.valid_range' => ['start' => '2026-10-15', 'end' => '2026-10-15']];

    expect(epCheck(settings: $rango))->toBeArray()
        // Acaba a las 15:00 del 16: se sale por el final.
        ->and(epCheck(['end' => '2026-10-16 15:00:00'], settings: $rango))->toBe('Fuera del rango permitido')
        // Sin fin se mira lo que ocuparía por defecto (1 h): cabe.
        ->and(epCheck(['start' => '2026-10-15 23:00:00', 'end' => null], settings: $rango))->toBeArray()
        // Y esa hora por defecto lo saca del día.
        ->and(epCheck(['start' => '2026-10-15 23:30:00', 'end' => null], settings: $rango))->toBe('Fuera del rango permitido');
});
