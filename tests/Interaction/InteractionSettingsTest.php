<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Interaction\Editability;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\View\CalendarSettings;

require_once __DIR__.'/helpers.php';

// ── Ajustes de interacción en CalendarSettings ─────────────────────────

it('la interacción viene apagada de fábrica y el snap es el slot', function () {
    $s = CalendarSettings::make(['date' => '2026-10-16']);

    expect($s->editable)->toBeFalse()
        ->and($s->selectable)->toBeFalse()
        ->and($s->snapSeconds)->toBe(1800)
        ->and($s->eventOverlap)->toBeTrue()
        ->and($s->allDayMaintainDuration)->toBeFalse()
        ->and($s->resourceEditable)->toBeTrue()
        ->and($s->validRange)->toBeNull();
});

it('lee los ajustes con la cascada de siempre: override > config', function () {
    config([
        'kore-calendar.interaction.editable' => true,
        'kore-calendar.interaction.event_overlap' => false,
        'kore-calendar.interaction.snap' => '10m',
    ]);

    $deConfig = CalendarSettings::make(['time.slot_duration' => '30m']);
    $conOverride = CalendarSettings::make([
        'time.slot_duration' => '30m',
        'interaction.editable' => false,
        'interaction.selectable' => true,
        'interaction.snap' => '15m',
        'interaction.event_overlap' => true,
        'interaction.all_day_maintain_duration' => true,
        'interaction.resource_editable' => false,
    ]);

    expect($deConfig->editable)->toBeTrue()
        ->and($deConfig->eventOverlap)->toBeFalse()
        ->and($deConfig->snapSeconds)->toBe(600)
        ->and($conOverride->editable)->toBeFalse()
        ->and($conOverride->selectable)->toBeTrue()
        ->and($conOverride->snapSeconds)->toBe(900)
        ->and($conOverride->eventOverlap)->toBeTrue()
        ->and($conOverride->allDayMaintainDuration)->toBeTrue()
        ->and($conOverride->resourceEditable)->toBeFalse();
});

it('el snap que no divide el slot cae al slot, como en el cliente', function (string $slot, string $snap, int $esperado) {
    expect(CalendarSettings::make(['time.slot_duration' => $slot, 'interaction.snap' => $snap])->snapSeconds)->toBe($esperado);
})->with([
    'divide' => ['30m', '15m', 900],
    'es el slot' => ['30m', '30m', 1800],
    'no divide (20 de 30)' => ['30m', '20m', 1800],
    'mayor que el slot' => ['30m', '1h', 1800],
    'en segundos' => ['1h', '30s', 30],
    'cero' => ['30m', '0s', 1800],
]);

it('un snap que no es una duración falla con un mensaje claro', function () {
    expect(fn () => CalendarSettings::make(['interaction.snap' => 'rápido']))->toThrow(InvalidArgumentException::class, 'duración');
});

it('valid_range: el fin incluye ese día y el rango es semiabierto', function () {
    $s = CalendarSettings::make(['interaction.valid_range' => ['start' => '2026-10-12', 'end' => '2026-10-18']]);

    expect((string) $s->validRange->start)->toBe('2026-10-12 00:00:00')
        ->and((string) $s->validRange->end)->toBe('2026-10-19 00:00:00')
        ->and($s->validRange->contains(Marker::parse('2026-10-18 23:59:59')))->toBeTrue()
        ->and($s->validRange->contains(Marker::parse('2026-10-19')))->toBeFalse();
});

it('valid_range con un solo extremo, o vacío', function () {
    $soloInicio = CalendarSettings::make(['interaction.valid_range' => ['start' => '2026-10-12', 'end' => null]]);
    $soloFin = CalendarSettings::make(['interaction.valid_range' => ['start' => null, 'end' => '2026-10-18']]);
    $vacio = CalendarSettings::make(['interaction.valid_range' => ['start' => null, 'end' => '']]);

    expect($soloInicio->validRange->contains(Marker::parse('2500-01-01')))->toBeTrue()
        ->and($soloInicio->validRange->contains(Marker::parse('2026-10-11')))->toBeFalse()
        ->and($soloFin->validRange->contains(Marker::parse('1900-01-01')))->toBeTrue()
        ->and($soloFin->validRange->contains(Marker::parse('2026-10-19')))->toBeFalse()
        ->and($vacio->validRange)->toBeNull();
});

it('valid_range mal escrito o invertido lanza con el nombre del ajuste', function (array $range) {
    expect(fn () => CalendarSettings::make(['interaction.valid_range' => $range]))
        ->toThrow(InvalidArgumentException::class, 'interaction.valid_range');
})->with([
    'no es una fecha' => [['start' => 'mañana', 'end' => null]],
    'invertido' => [['start' => '2026-10-18', 'end' => '2026-10-12']],
]);

it('with() conserva los ajustes de interacción', function () {
    $s = CalendarSettings::make([
        'interaction.editable' => true,
        'interaction.selectable' => true,
        'interaction.snap' => '15m',
        'interaction.event_overlap' => false,
        'interaction.all_day_maintain_duration' => true,
        'interaction.resource_editable' => false,
        'interaction.valid_range' => ['start' => '2026-01-01', 'end' => '2026-12-31'],
    ]);

    $otro = $s->with(view: 'week');

    expect($otro->view)->toBe('week')
        ->and($otro->editable)->toBeTrue()
        ->and($otro->selectable)->toBeTrue()
        ->and($otro->snapSeconds)->toBe(900)
        ->and($otro->eventOverlap)->toBeFalse()
        ->and($otro->allDayMaintainDuration)->toBeTrue()
        ->and($otro->resourceEditable)->toBeFalse()
        ->and($otro->validRange)->toBe($s->validRange);
});

// ── Editability ────────────────────────────────────────────────────────

it('Editability: la cascada evento → calendario con ?? (null no es false)', function (array $evento, bool $calendario, bool $mover, bool $redimensionar) {
    $e = CalendarEvent::make(...[...['id' => 1, 'title' => 'x', 'start' => '2026-10-14 10:00'], ...$evento]);
    $s = ixSettings(['interaction.editable' => $calendario]);

    expect(Editability::canMove($e, $s))->toBe($mover)
        ->and(Editability::canResize($e, $s))->toBe($redimensionar);
})->with([
    'sin nada, calendario cerrado' => [[], false, false, false],
    'sin nada, calendario abierto' => [[], true, true, true],
    'editable true abre un calendario cerrado' => [['editable' => true], false, true, true],
    'editable false cierra uno abierto' => [['editable' => false], true, false, false],
    'startEditable gana a editable al mover' => [['editable' => false, 'startEditable' => true], false, true, false],
    'durationEditable gana a editable al redimensionar' => [['editable' => false, 'durationEditable' => true], false, false, true],
    'startEditable false deja redimensionar' => [['startEditable' => false], true, false, true],
    'durationEditable false deja mover' => [['durationEditable' => false], true, true, false],
    'el específico gana al general' => [['editable' => true, 'startEditable' => false, 'durationEditable' => false], true, false, false],
]);

it('Editability::canChangeResource = poder mover + resource_editable', function (array $evento, bool $resourceEditable, bool $esperado) {
    $e = CalendarEvent::make(...[...['id' => 1, 'title' => 'x', 'start' => '2026-10-14 10:00'], ...$evento]);
    $s = ixSettings(['interaction.resource_editable' => $resourceEditable]);

    expect(Editability::canChangeResource($e, $s))->toBe($esperado);
})->with([
    'todo abierto' => [[], true, true],
    'recursos cerrados' => [[], false, false],
    'el evento no se puede mover' => [['startEditable' => false], true, false],
    'redimensionable pero no movible' => [['editable' => false, 'durationEditable' => true], true, false],
]);
