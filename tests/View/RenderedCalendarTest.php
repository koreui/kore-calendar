<?php

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\View\CalendarRenderer;
use KoreUi\Calendar\View\CalendarSettings;
use KoreUi\Calendar\View\RenderedCalendar;

/**
 * Un evento largo (8 a 20) y `$others` cortos y seguidos dentro de él, sin tocarse entre sí:
 * el largo choca con todos, y cada corto solo con el largo.
 */
function rcConflicts(int $others, string $locale = 'es', array $titles = []): RenderedCalendar
{
    $events = [['id' => 'largo', 'title' => 'Largo', 'start' => '2026-10-14 08:00', 'end' => '2026-10-14 20:00']];

    for ($n = 1; $n <= $others; $n++) {
        $events[] = ['id' => $n, 'title' => $titles[$n - 1] ?? "Corto {$n}", 'start' => sprintf('2026-10-14 %02d:00', 8 + $n), 'end' => sprintf('2026-10-14 %02d:30', 8 + $n)];
    }

    return CalendarRenderer::render(
        CalendarSettings::make(['view' => 'week', 'date' => '2026-10-14', 'timezone' => 'UTC', 'locale' => $locale, 'highlight_conflicts' => true]),
        $events,
    );
}

function rcInstance(RenderedCalendar $calendar, string|int $id): EventInstance
{
    foreach ($calendar->layout->lanes as $lane) {
        foreach ($lane['events'] as $event) {
            if ((string) $event['instance']->event->id === (string) $id) {
                return $event['instance'];
            }
        }
    }

    throw new RuntimeException("Sin el evento {$id}");
}

// ── conflictLabel(): como mucho tres títulos y «y N más» ───────────────

it('hasta tres conflictos se nombran todos, igual que siempre', function () {
    $cal = rcConflicts(3);

    expect($cal->conflictLabel(rcInstance($cal, 'largo')))->toBe('En conflicto con «Corto 1», «Corto 2», «Corto 3»')
        ->and($cal->conflictLabel(rcInstance($cal, 2)))->toBe('En conflicto con «Largo»');
});

it('con más de tres, nombra tres y cuenta el resto: el total se conserva', function () {
    $cal = rcConflicts(7);

    expect($cal->conflictLabel(rcInstance($cal, 'largo')))->toBe('En conflicto con «Corto 1», «Corto 2», «Corto 3» y 4 más');

    $cal = rcConflicts(4);

    expect($cal->conflictLabel(rcInstance($cal, 'largo')))->toBe('En conflicto con «Corto 1», «Corto 2», «Corto 3» y 1 más');
});

it('en inglés usa sus comillas y su «and N more»', function () {
    $cal = rcConflicts(5, 'en');

    expect($cal->conflictLabel(rcInstance($cal, 'largo')))->toBe('Conflicts with “Corto 1”, “Corto 2”, “Corto 3” and 2 more');
});

it('dos eventos con el mismo título se nombran una vez, y no cuentan como «más»', function () {
    $cal = rcConflicts(5, titles: ['Igual', 'Igual', 'Otro', 'Igual', 'Último']);

    // Cinco conflictos, tres títulos distintos: se nombran los tres primeros y el quinto («Último») se cuenta.
    expect($cal->conflictLabel(rcInstance($cal, 'largo')))->toBe('En conflicto con «Igual», «Otro», «Último»');

    $cal = rcConflicts(6, titles: ['A', 'B', 'C', 'D', 'A', 'D']);

    // Los nombrados cubren a sus repetidos (A, B, C, A); D y D son dos eventos más.
    expect($cal->conflictLabel(rcInstance($cal, 'largo')))->toBe('En conflicto con «A», «B», «C» y 2 más');
});

it('un título con marcadores no se sustituye dentro del texto ya insertado', function () {
    $cal = rcConflicts(4, titles: [':count', ':titles', 'x', 'y']);

    expect($cal->conflictLabel(rcInstance($cal, 'largo')))->toBe('En conflicto con «:count», «:titles», «x» y 1 más');
});

it('un evento sin conflictos no tiene etiqueta', function () {
    $cal = rcConflicts(0);

    expect($cal->conflictLabel(rcInstance($cal, 'largo')))->toBeNull();
});

it('el HTML de un evento con muchos conflictos lleva la etiqueta recortada', function () {
    $cal = rcConflicts(11);
    $html = (string) test()->blade('<x-kore::calendar.time-grid :calendar="$calendar" />', ['calendar' => $cal]);

    expect($html)->toContain('En conflicto con «Corto 1», «Corto 2», «Corto 3» y 8 más')
        ->not->toContain('«Corto 4»');
});

// ── format(): una vez por (patrón, instante) ───────────────────────────

it('format() da lo mismo que sin memoria y no mezcla patrones ni instantes', function () {
    $cal = rcConflicts(0);
    $a = Marker::parse('2026-10-14 09:30');
    $b = Marker::parse('2026-10-14 17:05');

    $first = [$cal->time($a), $cal->time($b), $cal->format($a, 'D'), $cal->format($b, 'D'), $cal->format($a, 'title_day')];
    $again = [$cal->time($a), $cal->time($b), $cal->format($a, 'D'), $cal->format($b, 'D'), $cal->format($a, 'title_day')];

    expect($again)->toBe($first)
        ->and($first[0])->toBe('9:30')
        ->and($first[1])->toBe('17:05')
        ->and($first[2])->toBe($a->toCarbon()->locale('es')->isoFormat('D'))
        ->and($first[4])->toBe('miércoles, 14 de octubre de 2026');
});

it('format() calcula cada (patrón, instante) una sola vez', function () {
    $cal = rcConflicts(0);
    $memo = new ReflectionProperty($cal, 'formatted');

    foreach (range(1, 20) as $_) {
        $cal->time(Marker::parse('2026-10-14 09:30'));
        $cal->time(Marker::parse('2026-10-14 10:00'));
        $cal->format(Marker::parse('2026-10-14 09:30'), 'D');
    }

    $entries = array_map('count', $memo->getValue($cal));

    // Dos patrones; el de la hora con dos instantes y el del día con uno.
    expect(array_sum($entries))->toBe(3)->and($entries)->toHaveCount(2);
});

it('cada calendario formatea en su idioma: la memoria es de la instancia', function () {
    $es = rcConflicts(0, 'es');
    $en = rcConflicts(0, 'en');
    $date = Marker::parse('2026-10-14 15:30');

    expect($es->time($date))->toBe('15:30')
        ->and($en->time($date))->toBe('3:30 PM')
        ->and($es->time($date))->toBe('15:30');
});

// ── Conflictos de un timeline virtualizado: solo las filas montadas ────

it('en un timeline virtualizado, los eventos montados conservan todos sus conflictos y los demás no se buscan', function () {
    $resources = new KoreUi\Calendar\Resources\ResourceSet(array_map(fn ($i) => ['id' => "r{$i}", 'title' => sprintf('Recurso %03d', $i)], range(1, 150)));

    // Pares de eventos que chocan en cada recurso, y uno de varios recursos que pisa a uno montado (r1) y a otro que no lo está (r140).
    $events = [];
    foreach (range(1, 150) as $i) {
        $events[] = ['id' => "a{$i}", 'title' => "A{$i}", 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 12:00', 'resourceIds' => ["r{$i}"]];
        $events[] = ['id' => "b{$i}", 'title' => "B{$i}", 'start' => '2026-10-14 11:00', 'end' => '2026-10-14 13:00', 'resourceIds' => ["r{$i}"]];
    }
    $events[] = ['id' => 'doble', 'title' => 'Doble', 'start' => '2026-10-14 10:30', 'end' => '2026-10-14 11:30', 'resourceIds' => ['r1', 'r140']];

    $settings = fn () => CalendarSettings::make(['view' => 'timeline-day', 'date' => '2026-10-14', 'timezone' => 'UTC', 'highlight_conflicts' => true, 'timeline.window' => 20]);
    $ventana = CalendarRenderer::render($settings(), $events, $resources, virtualize: true);
    $todo = CalendarRenderer::render($settings(), $events, $resources, virtualize: false);

    expect($ventana->layout->rowsAfter)->toBeGreaterThan(0);

    $montados = [];
    foreach ($ventana->layout->rows as $row) {
        foreach ($row['events'] ?? [] as $event) {
            $montados[] = $event['instance'];
        }
    }

    expect($montados)->not->toBe([]);

    foreach ($montados as $instance) {
        $ids = fn (RenderedCalendar $c) => array_map(fn (EventInstance $o) => $o->instanceId, $c->conflictsOf($instance));

        expect($ids($ventana))->toBe($ids($todo), "evento {$instance->instanceId}")->and($ids($ventana))->not->toBe([]);
    }

    // El de dos recursos, montado por r1, sigue chocando con el de r140, que no se monta.
    $doble = array_values(array_filter($montados, fn (EventInstance $i) => $i->event->id === 'doble'))[0];
    $conChoque = array_map(fn (EventInstance $o) => $o->event->id, $ventana->conflictsOf($doble));

    expect($conChoque)->toContain('a140')->toContain('a1')
        // Y los eventos de una fila que no se monta no se buscan: ahorro, no efecto visible.
        ->and(count($ventana->conflicts))->toBeLessThan(count($todo->conflicts));
});
