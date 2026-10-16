<?php

use KoreUi\Calendar\Interaction\Bounds;
use KoreUi\Calendar\Interaction\Messages;
use KoreUi\Calendar\Interaction\MutationValidator;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => app()->setLocale('es'));

// ── Editabilidad ───────────────────────────────────────────────────────

it('rechaza mover en un calendario que no es editable', function () {
    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')];

    expect(ixValidateMove($vista, 1, '2026-10-14 12:00', ['interaction.editable' => false]))->toBe('Este evento no se puede editar')
        ->and(ixValidateMove($vista, 1, '2026-10-14 12:00'))->toBeNull();
});

it('la cascada: el evento manda sobre el calendario, en los dos sentidos', function () {
    $bloqueado = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['editable' => false]);
    $abierto = ixInstance(2, '2026-10-15 10:00', '2026-10-15 11:00', extra: ['editable' => true]);
    $vista = [$bloqueado, $abierto];

    expect(ixValidateMove($vista, 1, '2026-10-14 12:00'))->toBe('Este evento no se puede editar')
        ->and(ixValidateMove($vista, 2, '2026-10-15 12:00', ['interaction.editable' => false]))->toBeNull();
});

it('mover usa startEditable y redimensionar durationEditable, cada uno por su lado', function () {
    $soloMover = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['editable' => false, 'startEditable' => true]);
    $soloDuracion = ixInstance(2, '2026-10-15 10:00', '2026-10-15 11:00', extra: ['editable' => false, 'durationEditable' => true]);
    $vista = [$soloMover, $soloDuracion];
    $s = ixSettings();
    $validator = new MutationValidator($s);
    $builder = ixBuilderFor($s);

    expect(ixValidateMove($vista, 1, '2026-10-14 12:00'))->toBeNull()
        ->and($validator->validateResize($builder->resize($soloMover, ixMarker('2026-10-14 12:00')), $vista))->toBe('Este evento no se puede editar')
        ->and(ixValidateMove($vista, 2, '2026-10-15 12:00'))->toBe('Este evento no se puede editar')
        ->and($validator->validateResize($builder->resize($soloDuracion, ixMarker('2026-10-15 12:00')), $vista))->toBeNull();
});

it('cambiar de recurso exige poder mover y resource_editable; mover dentro del mismo recurso no', function () {
    $evento = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['resourceIds' => ['a']]);
    $fijo = ixInstance(2, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['resourceIds' => ['a'], 'editable' => false]);

    expect(ixValidateMove([$evento], 1, '2026-10-14 12:00', ['interaction.resource_editable' => false], resources: ['b']))->toBe('Este evento no se puede cambiar de recurso')
        // Mismo recurso, otra hora: es solo un movimiento.
        ->and(ixValidateMove([$evento], 1, '2026-10-14 12:00', ['interaction.resource_editable' => false], resources: ['a']))->toBeNull()
        ->and(ixValidateMove([$evento], 1, '2026-10-14 12:00', resources: ['b']))->toBeNull()
        // canChangeResource implica canMove: el rechazo es el de «no editable».
        ->and(ixValidateMove([$fijo], 2, '2026-10-14 12:00', resources: ['b']))->toBe('Este evento no se puede editar');
});

it('el texto sale de la traducción del config', function () {
    config(['kore-calendar.translations.not_editable' => 'Read only']);

    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')];

    expect(ixValidateMove($vista, 1, '2026-10-14 12:00', ['interaction.editable' => false]))->toBe('Read only');
});

// ── Rango válido ───────────────────────────────────────────────────────

it('el rango mutado entero tiene que caber en valid_range (el fin incluye ese día)', function (string $destino, ?string $error) {
    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00')];
    $range = ['interaction.valid_range' => ['start' => '2026-10-12', 'end' => '2026-10-18']];

    expect(ixValidateMove($vista, 1, $destino, $range))->toBe($error);
})->with([
    'dentro' => ['2026-10-15 10:00', null],
    'justo al principio' => ['2026-10-12 00:00', null],
    'termina justo a medianoche del día siguiente al fin' => ['2026-10-18 22:00', null],
    'empieza antes' => ['2026-10-11 23:00', 'Fuera del rango permitido'],
    'empieza dentro y acaba fuera' => ['2026-10-18 23:00', 'Fuera del rango permitido'],
    'empieza fuera' => ['2026-10-19 10:00', 'Fuera del rango permitido'],
]);

it('un valid_range con un solo extremo deja el otro abierto', function () {
    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00')];

    expect(ixValidateMove($vista, 1, '2040-01-01 10:00', ['interaction.valid_range' => ['start' => '2026-01-01', 'end' => null]]))->toBeNull()
        ->and(ixValidateMove($vista, 1, '2025-12-31 10:00', ['interaction.valid_range' => ['start' => '2026-01-01', 'end' => null]]))->toBe('Fuera del rango permitido')
        ->and(ixValidateMove($vista, 1, '2000-01-01 10:00', ['interaction.valid_range' => ['start' => null, 'end' => '2026-12-31']]))->toBeNull()
        ->and(ixValidateMove($vista, 1, '2027-01-01 10:00', ['interaction.valid_range' => ['start' => null, 'end' => '2026-12-31']]))->toBe('Fuera del rango permitido');
});

it('un día completo se comprueba contra el rango con su fin exclusivo', function () {
    $dias = ixInstance(1, '2026-10-14', '2026-10-16');
    $range = ['interaction.valid_range' => ['start' => '2026-10-12', 'end' => '2026-10-18']];

    expect(ixValidateMove([$dias], 1, '2026-10-17', $range, allDay: true))->toBeNull()
        // [18, 20) se sale: el 19 ya no es válido.
        ->and(ixValidateMove([$dias], 1, '2026-10-18', $range, allDay: true))->toBe('Fuera del rango permitido');
});

it('redimensionar también respeta valid_range', function () {
    $evento = ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00');
    $s = ixSettings(['interaction.valid_range' => ['start' => '2026-10-14', 'end' => '2026-10-14']]);
    $validator = new MutationValidator($s);
    $builder = ixBuilderFor($s);

    expect($validator->validateResize($builder->resize($evento, ixMarker('2026-10-14 23:00')), [$evento]))->toBeNull()
        ->and($validator->validateResize($builder->resize($evento, ixMarker('2026-10-15 01:00')), [$evento]))->toBe('Fuera del rango permitido')
        ->and($validator->validateResize($builder->resize($evento, ixMarker('2026-10-13 23:00'), fromStart: true), [$evento]))->toBe('Fuera del rango permitido');
});

it('una selección fuera del rango válido se rechaza, dentro no', function () {
    $s = ixSettings(['interaction.valid_range' => ['start' => '2026-10-12', 'end' => '2026-10-18']]);
    $validator = new MutationValidator($s);
    $builder = ixBuilderFor($s);

    expect($validator->validateSelection($builder->select(ixMarker('2026-10-13 09:00'), ixMarker('2026-10-13 10:00'), false)))->toBeNull()
        ->and($validator->validateSelection($builder->select(ixMarker('2026-10-18 23:00'), ixMarker('2026-10-19 01:00'), false)))->toBe('Fuera del rango permitido')
        ->and($validator->validateSelection($builder->select(ixMarker('2026-10-01'), ixMarker('2026-10-02'), true)))->toBe('Fuera del rango permitido');
});

// ── Solapes ────────────────────────────────────────────────────────────

it('por defecto los eventos se pueden solapar', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00'),
    ];

    expect(ixValidateMove($vista, 1, '2026-10-14 14:30'))->toBeNull();
});

it('con event_overlap false rechaza si los intervalos se intersecan y dice con cuál', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['title' => 'Revisión «trimestral»']),
    ];
    $estricto = ['interaction.event_overlap' => false];

    expect(ixValidateMove($vista, 1, '2026-10-14 14:30', $estricto))->toBe('Se solapa con «Revisión «trimestral»»')
        ->and(ixValidateMove($vista, 1, '2026-10-14 13:30', $estricto))->toBe('Se solapa con «Revisión «trimestral»»');
});

it('los intervalos son semiabiertos: tocarse en un borde no es solaparse', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00'),
    ];
    $estricto = ['interaction.event_overlap' => false];

    expect(ixValidateMove($vista, 1, '2026-10-14 13:00', $estricto))->toBeNull()   // acaba justo cuando empieza el otro
        ->and(ixValidateMove($vista, 1, '2026-10-14 15:00', $estricto))->toBeNull()  // empieza justo cuando acaba el otro
        ->and(ixValidateMove($vista, 1, '2026-10-14 13:30', $estricto))->toBe('Se solapa con «Evento 2»'); // 30 min dentro
});

it('el evento no colisiona consigo mismo', function () {
    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')];

    // Se mueve 15 min: sigue solapándose con su propia posición anterior, y eso no cuenta.
    expect(ixValidateMove($vista, 1, '2026-10-14 10:15', ['interaction.event_overlap' => false]))->toBeNull();
});

it('overlap false en el evento movido bloquea aunque el calendario permita solapes', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['overlap' => false]),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00'),
    ];

    expect(ixValidateMove($vista, 1, '2026-10-14 14:30'))->toBe('Se solapa con «Evento 2»');
});

it('overlap false en el OTRO evento bloquea aunque el movido lo permita', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['overlap' => false]),
    ];

    expect(ixValidateMove($vista, 1, '2026-10-14 14:30'))->toBe('Se solapa con «Evento 2»')
        ->and(ixValidateMove($vista, 1, '2026-10-14 16:00'))->toBeNull();
});

it('los eventos del mismo groupId no colisionan entre sí', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['groupId' => 'g1', 'overlap' => false]),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['groupId' => 'g1', 'overlap' => false]),
        ixInstance(3, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['groupId' => 'g2']),
        ixInstance(4, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['overlap' => false]),
    ];
    $estricto = ['interaction.event_overlap' => false];

    // Encima del hermano del grupo no choca; el 3 (grupo distinto) y el 4 (sin grupo) sí.
    $sinOtros = [$vista[0], $vista[1]];

    expect(ixValidateMove($sinOtros, 1, '2026-10-14 14:30', $estricto))->toBeNull()
        ->and(ixValidateMove([$vista[0], $vista[2]], 1, '2026-10-14 14:30', $estricto))->toBe('Se solapa con «Evento 3»')
        ->and(ixValidateMove([$vista[0], $vista[3]], 1, '2026-10-14 14:30', $estricto))->toBe('Se solapa con «Evento 4»');
});

it('sin groupId, dos eventos que se parecen no son un grupo', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00'),
    ];

    expect($vista[0]->event->groupId)->toBeNull()
        ->and(ixValidateMove($vista, 1, '2026-10-14 14:30', ['interaction.event_overlap' => false]))->not->toBeNull();
});

it('un evento de fondo no bloquea por la regla general, pero sí si dice overlap false', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['display' => 'background']),
        ixInstance(3, '2026-10-14 18:00', '2026-10-14 19:00', extra: ['display' => 'background', 'overlap' => false, 'title' => 'Cierre']),
    ];
    $estricto = ['interaction.event_overlap' => false];

    expect(ixValidateMove($vista, 1, '2026-10-14 14:30', $estricto))->toBeNull()
        ->and(ixValidateMove($vista, 1, '2026-10-14 18:30', $estricto))->not->toBeNull();
});

it('el rechazo por solapar nombra al otro evento solo si se dibuja: el título de uno oculto no se filtra', function (string $display, bool $seNombra) {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 18:00', '2026-10-14 19:00', extra: ['display' => $display, 'overlap' => false, 'title' => 'TITULO-PRIVADO']),
    ];

    $motivo = ixValidateMove($vista, 1, '2026-10-14 18:30');

    expect($motivo)->not->toBeNull()
        ->and(str_contains($motivo, 'TITULO-PRIVADO'))->toBe($seNombra);
})->with([
    'auto' => ['auto', true],
    'block' => ['block', true],
    'list-item' => ['list-item', true],
    'background' => ['background', false],
    'none' => ['none', false],
]);

it('un día completo y uno con hora del mismo día se solapan', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-16', '2026-10-17'),
    ];

    expect(ixValidateMove($vista, 1, '2026-10-16 10:00', ['interaction.event_overlap' => false]))->toBe('Se solapa con «Evento 2»')
        ->and(ixValidateMove($vista, 1, '2026-10-17 10:00', ['interaction.event_overlap' => false]))->toBeNull();
});

it('redimensionar también comprueba los solapes', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 12:00', '2026-10-14 13:00'),
    ];
    $s = ixSettings(['interaction.event_overlap' => false]);
    $validator = new MutationValidator($s);
    $builder = ixBuilderFor($s);

    expect($validator->validateResize($builder->resize($vista[0], ixMarker('2026-10-14 12:00')), $vista))->toBeNull()
        ->and($validator->validateResize($builder->resize($vista[0], ixMarker('2026-10-14 12:30')), $vista))->toBe('Se solapa con «Evento 2»');
});

// ── Recursos ───────────────────────────────────────────────────────────

it('con recursos, solo cuentan los eventos que comparten alguno con el destino', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['resourceIds' => ['a']]),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['resourceIds' => ['b']]),
        ixInstance(3, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['resourceIds' => ['a', 'c']]),
    ];
    $estricto = ['interaction.event_overlap' => false];

    // A las 14:30, el 1 (sala a) choca con el 3 (a, c) pero no con el 2 (b).
    expect(ixValidateMove($vista, 1, '2026-10-14 14:30', $estricto))->toBe('Se solapa con «Evento 3»')
        ->and(ixValidateMove([$vista[0], $vista[1]], 1, '2026-10-14 14:30', $estricto))->toBeNull();
});

it('el recurso que cuenta es el DESTINO: pasar a otra sala hace que choque con los de esa sala', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['resourceIds' => ['a']]),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['resourceIds' => ['b']]),
    ];
    $estricto = ['interaction.event_overlap' => false];

    expect(ixValidateMove($vista, 1, '2026-10-14 14:30', $estricto, resources: ['a']))->toBeNull()
        ->and(ixValidateMove($vista, 1, '2026-10-14 14:30', $estricto, resources: ['b']))->toBe('Se solapa con «Evento 2»');
});

it('sin recursos todo comparte carril; un evento sin recurso no choca con uno asignado', function () {
    $sinRecurso = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00'),
    ];
    $mixto = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00'),
        ixInstance(2, '2026-10-14 14:00', '2026-10-14 15:00', extra: ['resourceIds' => ['a']]),
    ];
    $estricto = ['interaction.event_overlap' => false];

    expect(ixValidateMove($sinRecurso, 1, '2026-10-14 14:30', $estricto))->not->toBeNull()
        ->and(ixValidateMove($mixto, 1, '2026-10-14 14:30', $estricto))->toBeNull();
});

// ── Instantes exactos (pliegue de otoño) ───────────────────────────────

it('con instantes exactos, las dos pasadas de la hora repetida no colisionan', function () {
    // Mismo marker (02:10-02:40), instantes distintos: la primera pasada es +02:00 y la segunda +01:00.
    $primera = ixInstance(2, '2026-10-25T02:10:00+02:00', '2026-10-25T02:40:00+02:00', extra: ['resourceIds' => ['b']]);
    $segunda = ixInstance(1, '2026-10-25T02:10:00+01:00', '2026-10-25T02:40:00+01:00', extra: ['resourceIds' => ['a']]);
    $estricto = ['interaction.event_overlap' => false];

    // Cambia de sala sin tocar la hora (se conserva el instante de la segunda pasada).
    expect(ixValidateMove([$segunda, $primera], 1, '2026-10-25 02:10', $estricto, resources: ['b']))->toBeNull();

    // Con la otra hora flotante (sin instante) solo hay markers y la colisión es real.
    $flotante = ixInstance(2, '2026-10-25 02:10', '2026-10-25 02:40', extra: ['resourceIds' => ['b']]);

    expect(ixValidateMove([$segunda, $flotante], 1, '2026-10-25 02:10', $estricto, resources: ['b']))->toBe('Se solapa con «Evento 2»');
});

// ── Orden de las comprobaciones y límites ──────────────────────────────

it('devuelve el primer motivo: editabilidad antes que rango antes que solapes', function () {
    $vista = [
        ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['editable' => false]),
        ixInstance(2, '2026-10-20 10:00', '2026-10-20 11:00'),
    ];
    $todo = ['interaction.event_overlap' => false, 'interaction.valid_range' => ['start' => '2026-10-12', 'end' => '2026-10-18']];

    expect(ixValidateMove($vista, 1, '2026-10-20 10:30', $todo))->toBe('Este evento no se puede editar');

    $vista[0] = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00');

    expect(ixValidateMove($vista, 1, '2026-10-20 10:30', $todo))->toBe('Fuera del rango permitido');
});

it('los límites de fecha del servidor: años 1 a 9999', function () {
    expect(Bounds::contains(0, ixMarker('2026-10-14')->ts, ixMarker('9999-12-31 23:59:59')->ts, ixMarker('0001-01-01')->ts))->toBeTrue()
        ->and(Bounds::contains(PHP_INT_MAX))->toBeFalse()
        ->and(Bounds::contains(PHP_INT_MIN))->toBeFalse()
        ->and(Bounds::contains(Bounds::MAX))->toBeFalse()
        ->and(Bounds::contains(0, -62_135_596_801))->toBeFalse();

    // Y la validación lo rechaza aunque no haya valid_range.
    $vista = [ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00')];

    expect(ixValidateMove($vista, 1, '9999-12-31 23:30'))->toBe('Fuera del rango permitido');
});

it('Messages sustituye los marcadores a la vez: un título con «:date» no se vuelve a sustituir', function () {
    config(['kore-calendar.translations.event_moved' => '«:title» movido al :date']);

    expect(Messages::get('event_moved', ['title' => 'Cita :date', 'date' => '1 de enero']))
        ->toBe('«Cita :date» movido al 1 de enero')
        ->and(Messages::get('no_existe'))->toBe('no_existe');
});
