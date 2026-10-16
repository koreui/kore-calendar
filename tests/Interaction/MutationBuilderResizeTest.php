<?php

require_once __DIR__.'/helpers.php';

/*
| Redimensionar (fc-edge-cases 4.4) y seleccionar (4.2).
*/

// ── Redimensionar ──────────────────────────────────────────────────────

it('arrastrar el borde final cambia el fin y deja el inicio', function () {
    $timed = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00');

    $m = ixBuilder()->resize($timed, ixMarker('2026-10-14 12:30'));

    expect(ixIso($m->start))->toBe('2026-10-14T10:00:00+02:00')
        ->and(ixIso($m->end))->toBe('2026-10-14T12:30:00+02:00')
        ->and((string) $m->endMarker)->toBe('2026-10-14 12:30:00')
        ->and($m->deltaSeconds)->toBe(5400)
        ->and($m->allDay)->toBeFalse()
        ->and($m->isNoop())->toBeFalse()
        ->and(ixIso($m->previousEnd))->toBe('2026-10-14T11:00:00+02:00');
});

it('arrastrar el borde inicial cambia el inicio y deja el fin', function () {
    $timed = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00');

    $m = ixBuilder()->resize($timed, ixMarker('2026-10-14 09:00'), fromStart: true);

    expect(ixIso($m->start))->toBe('2026-10-14T09:00:00+02:00')
        ->and(ixIso($m->end))->toBe('2026-10-14T11:00:00+02:00')
        ->and($m->deltaSeconds)->toBe(-3600)
        ->and($m->startDate())->toBe('2026-10-14');
});

it('nunca deja una duración cero o negativa: no hay mutación', function (string $edge, bool $fromStart) {
    $timed = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00');

    expect(ixBuilder()->resize($timed, ixMarker($edge), $fromStart))->toBeNull();
})->with([
    'fin igual al inicio' => ['2026-10-14 10:00', false],
    'fin antes del inicio' => ['2026-10-14 09:00', false],
    'fin que tras el snap cae en el inicio' => ['2026-10-14 10:10', false],
    'inicio igual al fin' => ['2026-10-14 11:00', true],
    'inicio después del fin' => ['2026-10-14 12:00', true],
    'inicio que tras el snap cae en el fin' => ['2026-10-14 10:50', true],
]);

it('re-ajusta el borde al snap antes de comprobar la duración', function () {
    $timed = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00');
    $builder = ixBuilder(['snapSeconds' => 900]);

    expect((string) $builder->resize($timed, ixMarker('2026-10-14 11:53'))->endMarker)->toBe('2026-10-14 12:00:00')
        ->and((string) $builder->resize($timed, ixMarker('2026-10-14 11:37'))->endMarker)->toBe('2026-10-14 11:30:00')
        ->and((string) $builder->resize($timed, ixMarker('2026-10-14 09:38'), fromStart: true)->startMarker)->toBe('2026-10-14 09:45:00');
});

it('dar un fin explícito a un evento que no lo tenía', function () {
    $sinFin = ixInstance(1, '2026-10-14 10:00');

    $a = ixBuilder()->resize($sinFin, ixMarker('2026-10-14 12:00'));
    // Redimensionar desde el inicio también lo materializa: el fin implícito pasa a ser un dato.
    $b = ixBuilder()->resize($sinFin, ixMarker('2026-10-14 09:00'), fromStart: true);

    expect(ixIso($a->end))->toBe('2026-10-14T12:00:00+02:00')
        ->and(ixIso($b->end))->toBe('2026-10-14T11:00:00+02:00')
        ->and($a->previousEnd)->toBeNull();
});

it('el recurso no cambia al redimensionar', function () {
    $timed = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['resourceIds' => ['a', 'b']]);

    $m = ixBuilder()->resize($timed, ixMarker('2026-10-14 12:00'));

    expect($m->resourceIds)->toBe(['a', 'b'])->and($m->isResourceChange())->toBeFalse();
});

it('soltar el borde donde estaba es un no-op', function () {
    $timed = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00');

    expect(ixBuilder()->resize($timed, ixMarker('2026-10-14 11:05'))->isNoop())->toBeTrue()
        ->and(ixBuilder()->resize($timed, ixMarker('2026-10-14 10:00'), fromStart: true)->isNoop())->toBeTrue();
});

// ── Día completo ───────────────────────────────────────────────────────

it('un día completo se redimensiona a días enteros', function () {
    $allDay = ixInstance(1, '2026-09-03', '2026-09-05');

    $fin = ixBuilder()->resize($allDay, ixMarker('2026-09-08'));
    $inicio = ixBuilder()->resize($allDay, ixMarker('2026-09-02'), fromStart: true);

    expect($fin->allDay)->toBeTrue()
        ->and($fin->startDate())->toBe('2026-09-03')
        ->and($fin->endDate())->toBe('2026-09-08')
        ->and(ixIso($fin->end))->toBe('2026-09-08T00:00:00+02:00')
        ->and($inicio->startDate())->toBe('2026-09-02')
        ->and($inicio->endDate())->toBe('2026-09-05');
});

it('un borde de día completo con hora se queda en el inicio de su día', function () {
    $allDay = ixInstance(1, '2026-09-03', '2026-09-05');

    $m = ixBuilder(['snapSeconds' => 900])->resize($allDay, ixMarker('2026-09-06 15:20'));

    expect((string) $m->endMarker)->toBe('2026-09-06 00:00:00');
});

it('un día completo no puede quedar en cero días', function () {
    $allDay = ixInstance(1, '2026-09-03', '2026-09-05');

    expect(ixBuilder()->resize($allDay, ixMarker('2026-09-03')))->toBeNull()
        ->and(ixBuilder()->resize($allDay, ixMarker('2026-09-03 18:00')))->toBeNull()
        ->and(ixBuilder()->resize($allDay, ixMarker('2026-09-05'), fromStart: true))->toBeNull()
        ->and(ixBuilder()->resize($allDay, ixMarker('2026-09-02')))->toBeNull();
});

// ── Horario de verano ──────────────────────────────────────────────────

it('Madrid 2026-10-25: alargar un evento hasta después del pliegue mide en instantes', function () {
    $hora = ixInstance(1, '2026-10-25T01:30:00+02:00', '2026-10-25T02:30:00+02:00');

    $m = ixBuilder()->resize($hora, ixMarker('2026-10-25 03:00'));

    // 03:00 es +01:00; entre 01:30+02:00 y 03:00+01:00 hay 2,5 h reales y solo 1,5 de pared.
    expect(ixIso($m->end))->toBe('2026-10-25T03:00:00+01:00')
        ->and($m->end->getTimestamp() - $m->start->getTimestamp())->toBe(9000)
        ->and((string) $m->endMarker)->toBe('2026-10-25 03:00:00');
});

it('en la segunda pasada del pliegue el inicio exacto no se pierde al mover solo el fin', function () {
    $segunda = ixInstance(1, '2026-10-25T02:30:00+01:00', '2026-10-25T03:30:00+01:00');

    $m = ixBuilder()->resize($segunda, ixMarker('2026-10-25 04:30'));

    expect(ixIso($m->start))->toBe('2026-10-25T02:30:00+01:00')
        ->and(ixIso($m->end))->toBe('2026-10-25T04:30:00+01:00');
});

it('el cliente no puede expresar la segunda pasada: un fin que en pared cae en el inicio se resuelve a la primera y se rechaza', function () {
    $segunda = ixInstance(1, '2026-10-25T02:30:00+01:00', '2026-10-25T03:30:00+01:00');

    // 02:30 es la primera pasada (+02:00), anterior al inicio de este evento.
    expect(ixBuilder()->resize($segunda, ixMarker('2026-10-25 02:30')))->toBeNull();
});

// ── Seleccionar ────────────────────────────────────────────────────────

it('ordena los bordes (arrastrar hacia arriba los da al revés) y los ajusta al snap', function () {
    $s = ixBuilder(['snapSeconds' => 900])->select(ixMarker('2026-10-14 11:53'), ixMarker('2026-10-14 10:07'), allDay: false, resourceId: 'a');

    expect($s->allDay)->toBeFalse()
        ->and((string) $s->startMarker)->toBe('2026-10-14 10:00:00')
        ->and((string) $s->endMarker)->toBe('2026-10-14 12:00:00')
        ->and(ixIso($s->start))->toBe('2026-10-14T10:00:00+02:00')
        ->and(ixIso($s->end))->toBe('2026-10-14T12:00:00+02:00')
        ->and($s->resourceId)->toBe('a');
});

it('una selección que el snap deja vacía se amplía a un paso', function () {
    $s = ixBuilder(['snapSeconds' => 900])->select(ixMarker('2026-10-14 10:02'), ixMarker('2026-10-14 10:05'), allDay: false);

    expect((string) $s->startMarker)->toBe('2026-10-14 10:00:00')
        ->and((string) $s->endMarker)->toBe('2026-10-14 10:15:00')
        ->and($s->resourceId)->toBeNull();
});

it('una selección de día completo son días enteros, con el fin exclusivo', function () {
    $s = ixBuilder()->select(ixMarker('2026-09-05 16:00'), ixMarker('2026-09-03 08:00'), allDay: true);

    expect($s->allDay)->toBeTrue()
        ->and((string) $s->startMarker)->toBe('2026-09-03 00:00:00')
        ->and((string) $s->endMarker)->toBe('2026-09-05 00:00:00')
        ->and(ixIso($s->start))->toBe('2026-09-03T00:00:00+02:00');

    // Un día suelto: el fin se amplía al día siguiente.
    $uno = ixBuilder()->select(ixMarker('2026-09-03 08:00'), ixMarker('2026-09-03 08:00'), allDay: true);

    expect((string) $uno->endMarker)->toBe('2026-09-04 00:00:00');
});

it('Madrid 2026-03-29: una selección que empieza en la hora que no existe se resuelve hacia delante', function () {
    $s = ixBuilder()->select(ixMarker('2026-03-29 02:30'), ixMarker('2026-03-29 04:00'), allDay: false);

    expect(ixIso($s->start))->toBe('2026-03-29T03:30:00+02:00')
        ->and((string) $s->startMarker)->toBe('2026-03-29 03:30:00')
        ->and(ixIso($s->end))->toBe('2026-03-29T04:00:00+02:00');
});
