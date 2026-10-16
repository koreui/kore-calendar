<?php

use KoreUi\Calendar\Interaction\MutationBuilder;

require_once __DIR__.'/helpers.php';

/*
| Mover: fc-edge-cases 4.3 (arrastre día completo ↔ con hora) y la parte de
| §5 de la spec que habla de volver de hora de pared a instante.
*/

// ── Día completo ↔ con hora (fc-edge-cases 4.3, event-drag/all-day-change.ts) ──

it('día completo [3-sep, 5-sep) arrastrado a las 02:00 del 3-sep: sin mantener, el fin es el de por defecto', function () {
    $allDay = ixInstance(1, '2026-09-03', '2026-09-05');

    $m = ixBuilder()->move($allDay, ixMarker('2026-09-03 02:00'), allDay: false);

    expect($m->allDay)->toBeFalse()
        ->and(ixIso($m->start))->toBe('2026-09-03T02:00:00+02:00')
        // Una hora (default_timed_duration), no los dos días que tenía.
        ->and(ixIso($m->end))->toBe('2026-09-03T03:00:00+02:00')
        ->and((string) $m->endMarker)->toBe('2026-09-03 03:00:00')
        ->and($m->previousAllDay)->toBeTrue()
        ->and($m->isAllDayChange())->toBeTrue()
        ->and($m->deltaSeconds)->toBe(2 * 3600);
});

it('con all_day_maintain_duration conserva el rango: acaba el 5-sep a las 02:00', function () {
    $allDay = ixInstance(1, '2026-09-03', '2026-09-05');

    $m = ixBuilder(['allDayMaintainDuration' => true])->move($allDay, ixMarker('2026-09-03 02:00'), allDay: false);

    expect(ixIso($m->start))->toBe('2026-09-03T02:00:00+02:00')
        ->and(ixIso($m->end))->toBe('2026-09-05T02:00:00+02:00')
        ->and((string) $m->endMarker)->toBe('2026-09-05 02:00:00');
});

it('la duración por defecto de la modalidad nueva es la inyectada (4 h → fin a las 06:00)', function () {
    $allDay = ixInstance(1, '2026-09-03', '2026-09-05');

    $m = ixBuilder(['defaultTimedSeconds' => 4 * 3600])->move($allDay, ixMarker('2026-09-03 02:00'), allDay: false);

    expect(ixIso($m->end))->toBe('2026-09-03T06:00:00+02:00');
});

it('con hora → día completo, sin mantener: 00:00 del día y la duración por defecto de un día completo', function () {
    $timed = ixInstance(1, '2026-09-03 10:00', '2026-09-03 12:00');

    $m = ixBuilder()->move($timed, ixMarker('2026-09-08 14:45'), allDay: true);

    expect($m->allDay)->toBeTrue()
        // El inicio es el día entero, aunque el cliente mande la hora de la celda.
        ->and(ixIso($m->start))->toBe('2026-09-08T00:00:00+02:00')
        ->and(ixIso($m->end))->toBe('2026-09-09T00:00:00+02:00')
        ->and($m->startDate())->toBe('2026-09-08')
        ->and($m->endDate())->toBe('2026-09-09')
        ->and($m->previousAllDay)->toBeFalse();

    $dos = ixBuilder(['defaultAllDayDays' => 2])->move($timed, ixMarker('2026-09-08'), allDay: true);

    expect($dos->endDate())->toBe('2026-09-10');
});

it('con hora → día completo manteniendo: días enteros (suelo), con un mínimo de uno', function (string $start, string $end, string $expectedEnd) {
    $timed = ixInstance(1, $start, $end);

    $m = ixBuilder(['allDayMaintainDuration' => true])->move($timed, ixMarker('2026-09-10'), allDay: true);

    expect($m->endDate())->toBe($expectedEnd);
})->with([
    'mismo día, 2 h' => ['2026-09-03 10:00', '2026-09-03 12:00', '2026-09-11'],
    'casi dos días (1,96) → 1 día' => ['2026-09-03 10:00', '2026-09-05 09:00', '2026-09-11'],
    'justo tres días → 3' => ['2026-09-03 10:00', '2026-09-06 10:00', '2026-09-13'],
]);

it('día completo → día completo: se desplaza el rango y no se toca la modalidad', function () {
    $allDay = ixInstance(1, '2026-09-03', '2026-09-05');

    $m = ixBuilder()->move($allDay, ixMarker('2026-09-10 17:00'), allDay: true);

    expect($m->allDay)->toBeTrue()
        ->and($m->isAllDayChange())->toBeFalse()
        ->and($m->startDate())->toBe('2026-09-10')
        ->and($m->endDate())->toBe('2026-09-12')
        ->and($m->deltaSeconds)->toBe(7 * 86400);
});

it('un día completo sin fin se queda sin fin al moverlo, y uno con fin lo conserva materializado', function () {
    $sinFin = ixInstance(1, '2026-09-03');
    $conFin = ixInstance(2, '2026-09-03', '2026-09-05');

    $a = ixBuilder()->move($sinFin, ixMarker('2026-09-10'), allDay: true);
    $b = ixBuilder()->move($conFin, ixMarker('2026-09-10'), allDay: true);

    expect($a->end)->toBeNull()
        ->and($a->endDate())->toBeNull()
        // Pero el fin efectivo siempre está: lo usan los solapes.
        ->and($a->endInstant)->not->toBeNull()
        ->and((string) $a->endMarker)->toBe('2026-09-11 00:00:00')
        ->and($a->previousEnd)->toBeNull()
        ->and($b->end)->not->toBeNull()
        ->and($b->previousEnd)->not->toBeNull();
});

it('cambiar de modalidad materializa el fin aunque el evento no lo tuviera', function () {
    $sinFin = ixInstance(1, '2026-09-03');

    $m = ixBuilder()->move($sinFin, ixMarker('2026-09-03 09:00'), allDay: false);

    expect(ixIso($m->end))->toBe('2026-09-03T10:00:00+02:00');
});

// ── Con hora → con hora ────────────────────────────────────────────────

it('con hora → con hora: el inicio es el destino y la duración se conserva', function () {
    $timed = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:30');

    $m = ixBuilder()->move($timed, ixMarker('2026-10-15 14:00'), allDay: false);

    expect(ixIso($m->start))->toBe('2026-10-15T14:00:00+02:00')
        ->and(ixIso($m->end))->toBe('2026-10-15T15:30:00+02:00')
        ->and((string) $m->startMarker)->toBe('2026-10-15 14:00:00')
        ->and((string) $m->endMarker)->toBe('2026-10-15 15:30:00')
        ->and($m->deltaSeconds)->toBe(86400 + 4 * 3600)
        ->and(ixIso($m->previousStart))->toBe('2026-10-14T10:00:00+02:00')
        ->and(ixIso($m->previousEnd))->toBe('2026-10-14T11:30:00+02:00')
        ->and($m->isNoop())->toBeFalse();
});

it('un evento con hora sin fin se queda sin fin: no se inventa un dato que el evento no tenía', function () {
    $sinFin = ixInstance(1, '2026-10-14 10:00');

    $m = ixBuilder()->move($sinFin, ixMarker('2026-10-14 16:00'), allDay: false);

    expect($m->end)->toBeNull()
        ->and(ixIso($m->endInstant))->toBe('2026-10-14T17:00:00+02:00')
        ->and($m->previousEnd)->toBeNull();
});

it('devuelve la mutación con el evento original y los recursos que se le pasan', function () {
    $timed = ixInstance(7, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['resourceIds' => ['a', 'b']]);

    $igual = ixBuilder()->move($timed, ixMarker('2026-10-14 12:00'), allDay: false);
    $cambia = ixBuilder()->move($timed, ixMarker('2026-10-14 12:00'), allDay: false, resourceIds: ['c', 'b']);

    expect($igual->event)->toBe($timed->event)
        ->and($igual->resourceIds)->toBe(['a', 'b'])
        ->and($igual->isResourceChange())->toBeFalse()
        ->and($cambia->resourceIds)->toBe(['c', 'b'])
        ->and($cambia->previousResourceIds)->toBe(['a', 'b'])
        ->and($cambia->isResourceChange())->toBeTrue();
});

it('el orden de los recursos no es un cambio de recurso', function () {
    $timed = ixInstance(7, '2026-10-14 10:00', '2026-10-14 11:00', extra: ['resourceIds' => ['a', 'b']]);

    $m = ixBuilder()->move($timed, ixMarker('2026-10-14 12:00'), allDay: false, resourceIds: ['b', 'a']);

    expect($m->isResourceChange())->toBeFalse();
});

// ── Snap en el servidor ────────────────────────────────────────────────

it('re-ajusta el destino al snap más cercano (15 min), y los empates van hacia delante', function (string $destino, string $esperado) {
    $timed = ixInstance(1, '2026-10-14 09:00', '2026-10-14 10:00');

    $m = ixBuilder(['snapSeconds' => 900])->move($timed, ixMarker($destino), allDay: false);

    expect($m->startMarker->toDateTimeString())->toBe($esperado);
})->with([
    'ya en la rejilla' => ['2026-10-14 14:15:00', '2026-10-14 14:15:00'],
    'por debajo' => ['2026-10-14 14:07:00', '2026-10-14 14:00:00'],
    'por encima' => ['2026-10-14 14:08:00', '2026-10-14 14:15:00'],
    'empate exacto' => ['2026-10-14 14:07:30', '2026-10-14 14:15:00'],
    'segundos sueltos' => ['2026-10-14 14:14:59', '2026-10-14 14:15:00'],
    'a medianoche cruza el día' => ['2026-10-14 23:55:00', '2026-10-15 00:00:00'],
]);

it('la rejilla del snap cuenta desde slot_min_time, como el cliente', function () {
    // Eje desde las 08:10 con snap de 30 min: 08:10, 08:40, 09:10…
    $timed = ixInstance(1, '2026-10-14 08:10', '2026-10-14 09:10');
    $builder = ixBuilder(['snapSeconds' => 1800, 'slotMinSeconds' => 8 * 3600 + 600]);

    expect($builder->move($timed, ixMarker('2026-10-14 11:00'), allDay: false)->startMarker->toDateTimeString())
        // 11:00 está a 20 min de 10:40 y a 10 min de 11:10.
        ->toBe('2026-10-14 11:10:00')
        ->and($builder->snapTimed(ixMarker('2026-10-14 08:20'))->toDateTimeString())->toBe('2026-10-14 08:10:00')
        // Antes de la apertura del eje la cuenta sigue (negativos).
        ->and($builder->snapTimed(ixMarker('2026-10-14 07:50'))->toDateTimeString())->toBe('2026-10-14 07:40:00');
});

it('el día completo se ajusta al inicio del día, no al snap', function () {
    $allDay = ixInstance(1, '2026-09-03', '2026-09-04');

    $m = ixBuilder(['snapSeconds' => 900])->move($allDay, ixMarker('2026-09-10 23:59:59'), allDay: true);

    expect($m->startDate())->toBe('2026-09-10')
        ->and((string) $m->startMarker)->toBe('2026-09-10 00:00:00');
});

it('soltar el evento en la celda donde ya estaba no lo mueve, aunque su minuto no esté en la rejilla', function () {
    // 10:10 con snap de 15 min: el cliente manda el inicio de su celda (10:00), y
    // pasarlo al 10:15 más cercano movería un evento que nadie quiso mover.
    $fuera = ixInstance(1, '2026-10-14 10:10', '2026-10-14 11:10');
    $builder = ixBuilder(['snapSeconds' => 900]);

    foreach (['2026-10-14 10:00', '2026-10-14 10:10', '2026-10-14 10:14'] as $destino) {
        $m = $builder->move($fuera, ixMarker($destino), allDay: false);

        expect($m->isNoop())->toBeTrue()
            ->and((string) $m->startMarker)->toBe('2026-10-14 10:10:00')
            ->and((string) $m->endMarker)->toBe('2026-10-14 11:10:00');
    }

    // Otra celda sí lo mueve, y entonces sí se ajusta.
    expect($builder->move($fuera, ixMarker('2026-10-14 10:15'), allDay: false)->startMarker->toDateTimeString())->toBe('2026-10-14 10:15:00');
});

it('cambiar solo de recurso conserva la hora exacta del evento', function () {
    $timed = ixInstance(1, '2026-10-14 10:10', '2026-10-14 11:10', extra: ['resourceIds' => ['a']]);

    $m = ixBuilder(['snapSeconds' => 900])->move($timed, ixMarker('2026-10-14 10:00'), allDay: false, resourceIds: ['b']);

    expect($m->isNoop())->toBeFalse()
        ->and($m->isResourceChange())->toBeTrue()
        ->and((string) $m->startMarker)->toBe('2026-10-14 10:10:00')
        ->and($m->deltaSeconds)->toBe(0);
});

it('un destino de día completo nunca es «la misma celda», aunque el día coincida', function () {
    $timed = ixInstance(1, '2026-10-14 10:00', '2026-10-14 11:00');

    $m = ixBuilder()->move($timed, ixMarker('2026-10-14 10:00'), allDay: true);

    expect($m->isNoop())->toBeFalse()
        ->and($m->allDay)->toBeTrue();
});

// ── Horario de verano (§5 de la spec: conservar la duración REAL) ──────

it('Madrid 2026-10-25: un evento de 1 h real movido a las 01:30 acaba a las 02:30+02:00 y mide 1 h real', function () {
    $hora = ixInstance(1, '2026-10-24T10:00:00+02:00', '2026-10-24T11:00:00+02:00');

    $m = ixBuilder()->move($hora, ixMarker('2026-10-25 01:30'), allDay: false);

    // 01:30+02:00 + 3600 s = 02:30+02:00 (la primera pasada de 02:30).
    expect(ixIso($m->start))->toBe('2026-10-25T01:30:00+02:00')
        ->and(ixIso($m->end))->toBe('2026-10-25T02:30:00+02:00')
        ->and($m->end->getTimestamp() - $m->start->getTimestamp())->toBe(3600)
        ->and((string) $m->endMarker)->toBe('2026-10-25 02:30:00');
});

it('Madrid 2026-10-25: movido a las 02:30 (primera pasada) acaba a las 02:30+01:00, 1 h real, y el marker del fin no se queda vacío', function () {
    $hora = ixInstance(1, '2026-10-24T10:00:00+02:00', '2026-10-24T11:00:00+02:00');

    $m = ixBuilder()->move($hora, ixMarker('2026-10-25 02:30'), allDay: false);

    expect(ixIso($m->start))->toBe('2026-10-25T02:30:00+02:00')
        ->and(ixIso($m->end))->toBe('2026-10-25T02:30:00+01:00')
        ->and($m->end->getTimestamp() - $m->start->getTimestamp())->toBe(3600)
        // 02:30 y 02:30 en pared: se re-expresa como inicio + duración real (la misma regla del normalizador).
        ->and((string) $m->startMarker)->toBe('2026-10-25 02:30:00')
        ->and((string) $m->endMarker)->toBe('2026-10-25 03:30:00');
});

it('una duración real que cruza el pliegue se conserva en instantes, no en pared', function () {
    // 01:30+02:00 → 03:30+01:00: 2 h de pared pero 3 h reales.
    $cruza = ixInstance(1, '2026-10-25T01:30:00+02:00', '2026-10-25T03:30:00+01:00');
    expect($cruza->range->seconds())->toBe(2 * 3600);

    $m = ixBuilder()->move($cruza, ixMarker('2026-10-26 10:00'), allDay: false);

    // 3 h reales: 13:00. Sumar la duración de pared daría 12:00 (2 h).
    expect(ixIso($m->start))->toBe('2026-10-26T10:00:00+01:00')
        ->and(ixIso($m->end))->toBe('2026-10-26T13:00:00+01:00');
});

it('Madrid 2026-03-29: 2 h reales movidas a las 01:30 acaban a las 04:30+02:00 (el salto se come una hora de pared)', function () {
    $dos = ixInstance(1, '2026-03-28T10:00:00+01:00', '2026-03-28T12:00:00+01:00');

    $m = ixBuilder()->move($dos, ixMarker('2026-03-29 01:30'), allDay: false);

    expect(ixIso($m->start))->toBe('2026-03-29T01:30:00+01:00')
        ->and(ixIso($m->end))->toBe('2026-03-29T04:30:00+02:00')
        ->and($m->end->getTimestamp() - $m->start->getTimestamp())->toBe(2 * 3600)
        ->and((string) $m->endMarker)->toBe('2026-03-29 04:30:00');
});

it('Madrid 2026-03-29: un destino en la hora que no existe se resuelve hacia delante y el marker lo refleja', function () {
    $hora = ixInstance(1, '2026-03-28T10:00:00+01:00', '2026-03-28T11:00:00+01:00');

    $m = ixBuilder()->move($hora, ixMarker('2026-03-29 02:30'), allDay: false);

    expect(ixIso($m->start))->toBe('2026-03-29T03:30:00+02:00')
        // El marker concuerda con el instante, como en EventInstance.
        ->and((string) $m->startMarker)->toBe('2026-03-29 03:30:00')
        ->and(ixIso($m->end))->toBe('2026-03-29T04:30:00+02:00');
});

it('sin cambiar el marker de inicio se conserva su instante exacto: la segunda pasada del pliegue no salta a la primera', function () {
    // 02:30 en la SEGUNDA pasada (+01:00). Solo cambia de recurso.
    $segunda = ixInstance(1, '2026-10-25T02:30:00+01:00', '2026-10-25T03:30:00+01:00', extra: ['resourceIds' => ['a']]);

    $m = ixBuilder()->move($segunda, ixMarker('2026-10-25 02:30'), allDay: false, resourceIds: ['b']);

    expect(ixIso($m->start))->toBe('2026-10-25T02:30:00+01:00')
        ->and(ixIso($m->end))->toBe('2026-10-25T03:30:00+01:00')
        ->and($m->isNoop())->toBeFalse();
});

it('un evento con hora de pared flotante (sin offset) conserva la duración en pared', function () {
    $flotante = ixInstance(1, '2026-10-14 10:00', '2026-10-14 12:00');
    expect($flotante->exactStart)->toBeNull();

    $m = ixBuilder()->move($flotante, ixMarker('2026-10-20 08:00'), allDay: false);

    expect(ixIso($m->end))->toBe('2026-10-20T10:00:00+02:00')
        ->and((string) $m->endMarker)->toBe('2026-10-20 10:00:00');
});

it('en otra zona los instantes salen con su offset', function () {
    $tokio = new MutationBuilder(ixClock('Asia/Tokyo'), 1800);
    $evento = (new KoreUi\Calendar\Events\EventNormalizer(ixClock('Asia/Tokyo')))->normalize(
        KoreUi\Calendar\Events\CalendarEvent::make(id: 1, title: 'x', start: '2026-10-14 10:00', end: '2026-10-14 11:00')
    );

    $m = $tokio->move($evento, ixMarker('2026-10-15 09:00'), allDay: false);

    expect(ixIso($m->start))->toBe('2026-10-15T09:00:00+09:00');
});

// ── Recursos ───────────────────────────────────────────────────────────

it('resourcesAfterDrop: sustituye el recurso de origen y no toca los demás', function (array $actuales, string|int|null $destino, string|int|null $origen, ?array $esperado) {
    expect(MutationBuilder::resourcesAfterDrop($actuales, $destino, $origen))->toBe($esperado);
})->with([
    'sin destino no cambia' => [['a'], null, null, ['a']],
    'uno solo, sin origen' => [['a'], 'b', null, ['b']],
    'ninguno, sin origen: se asigna' => [[], 'b', null, ['b']],
    'varios con origen: cambia solo ese' => [['a', 'b'], 'c', 'a', ['c', 'b']],
    'varios con origen en la misma columna: igual' => [['a', 'b'], 'a', 'a', ['a', 'b']],
    'el destino ya estaba: se fusionan' => [['a', 'b'], 'b', 'a', ['b']],
    'origen que el evento no tiene' => [['a', 'b'], 'c', 'z', null],
    'varios sin origen y destino ajeno: ambiguo' => [['a', 'b'], 'c', null, null],
    'varios sin origen y destino propio: es solo la hora' => [['a', 'b'], 'b', null, ['a', 'b']],
    'ids numéricos como string' => [[1, 2], 3, '1', [3, 2]],
]);
