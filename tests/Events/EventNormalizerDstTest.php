<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Time\WallClock;

/*
 * Cambios de horario.
 *
 * Europe/Madrid 2026-03-29: a las 02:00 (+01:00) pasan a ser las 03:00 (+02:00);
 *   la hora 02:00-03:00 no existe.
 * Europe/Madrid 2026-10-25: a las 03:00 (+02:00) pasan a ser las 02:00 (+01:00);
 *   la hora 02:00-03:00 existe dos veces.
 * America/New_York 2024-03-10: 02:00 (−05:00) → 03:00 (−04:00).
 * America/New_York 2024-11-03: 02:00 (−04:00) → 01:00 (−05:00); se repite 01:00-02:00.
 *
 * Invariante que se vigila en todos: `range->end > range->start`. Un evento de
 * duración cero no se puede pintar, y en el pliegue el marker de "inicio + 1 h"
 * coincide con el del inicio.
 */

function evNormalizarDst(array $args, string $zona, int $segundos = 3600): EventInstance
{
    return (new EventNormalizer(new WallClock($zona), $segundos))
        ->normalize(CalendarEvent::make(...['id' => 'dst', 'title' => 't', ...$args]));
}

// ── Pliegue de otoño ─────────────────────────────────────────────────

it('Madrid 25-oct: sin fin, 02:30+02:00 más 1 h da el mismo marker; el fin se re-expresa como inicio + duración real', function () {
    $i = evNormalizarDst(['start' => '2026-10-25T02:30:00+02:00'], 'Europe/Madrid');

    expect((string) $i->range->start)->toBe('2026-10-25 02:30:00')
        ->and($i->hasEnd)->toBeFalse()
        // En tiempo real, el fin sí es 02:30+01:00...
        ->and($i->exactStart->format('c'))->toBe('2026-10-25T02:30:00+02:00')
        ->and($i->exactEnd->format('c'))->toBe('2026-10-25T02:30:00+01:00')
        ->and($i->exactEnd->getTimestamp() - $i->exactStart->getTimestamp())->toBe(3600)
        // ...pero en marker el rango no puede ser vacío: dura la hora real.
        ->and($i->range->end->ts)->toBeGreaterThan($i->range->start->ts)
        ->and($i->range->seconds())->toBe(3600)
        ->and((string) $i->range->end)->toBe('2026-10-25 03:30:00');
});

it('Madrid 25-oct: fin explícito 02:30+01:00 (una hora real después) no es vacío ni inválido', function () {
    $i = evNormalizarDst(['start' => '2026-10-25T02:30:00+02:00', 'end' => '2026-10-25T02:30:00+01:00'], 'Europe/Madrid');

    expect($i->hasEnd)->toBeTrue()
        ->and((string) $i->range->start)->toBe('2026-10-25 02:30:00')
        ->and($i->range->seconds())->toBe(3600)
        ->and((string) $i->range->end)->toBe('2026-10-25 03:30:00')
        ->and($i->exactEnd->format('c'))->toBe('2026-10-25T02:30:00+01:00');
});

it('Madrid 25-oct: el fin explícito conserva la duración real aunque su hora de pared sea anterior', function () {
    // 02:45+02:00 → 02:15+01:00: 30 min reales, pero 02:15 es anterior a 02:45.
    $i = evNormalizarDst(['start' => '2026-10-25T02:45:00+02:00', 'end' => '2026-10-25T02:15:00+01:00'], 'Europe/Madrid');

    expect($i->hasEnd)->toBeTrue()
        ->and($i->range->seconds())->toBe(1800)
        ->and((string) $i->range->end)->toBe('2026-10-25 03:15:00');
});

it('Madrid 25-oct: un fin anterior en instante pero posterior en hora de pared es inválido', function () {
    // Inicio 02:15+01:00 (segunda pasada, 01:15Z). Fin 02:45+02:00 (primera pasada, 00:45Z):
    // por marker 02:45 > 02:15 parecería válido, pero ocurre 30 min ANTES.
    $i = evNormalizarDst(['start' => '2026-10-25T02:15:00+01:00', 'end' => '2026-10-25T02:45:00+02:00'], 'Europe/Madrid');

    expect($i->hasEnd)->toBeFalse()
        ->and($i->range->seconds())->toBe(3600)
        ->and((string) $i->range->end)->toBe('2026-10-25 03:15:00');
});

it('Madrid 25-oct: la segunda pasada de la hora repetida tiene su propio instante y su propio rango', function () {
    $primera = evNormalizarDst(['start' => '2026-10-25T02:30:00+02:00', 'end' => '2026-10-25T02:50:00+02:00'], 'Europe/Madrid');
    $segunda = evNormalizarDst(['start' => '2026-10-25T02:30:00+01:00', 'end' => '2026-10-25T02:50:00+01:00'], 'Europe/Madrid');

    // Mismo marker, instantes distintos: por eso el colisionador usa el instante.
    expect($primera->range->equals($segunda->range))->toBeTrue()
        ->and($segunda->exactStart->getTimestamp() - $primera->exactStart->getTimestamp())->toBe(3600);
});

it('Madrid 25-oct: sin fin en la segunda pasada no hay ningún caso especial', function () {
    $i = evNormalizarDst(['start' => '2026-10-25T02:30:00+01:00'], 'Europe/Madrid');

    expect((string) $i->range->start)->toBe('2026-10-25 02:30:00')
        ->and((string) $i->range->end)->toBe('2026-10-25 03:30:00')
        ->and($i->exactEnd->format('c'))->toBe('2026-10-25T03:30:00+01:00');
});

it('Madrid 25-oct: una duración que cruza el pliegue entero (2 h) no necesita corrección', function () {
    $i = evNormalizarDst(['start' => '2026-10-25T02:30:00+02:00'], 'Europe/Madrid', 7200);

    // 02:30+02:00 + 2 h = 03:30+01:00.
    expect((string) $i->range->end)->toBe('2026-10-25 03:30:00')
        ->and($i->exactEnd->getTimestamp() - $i->exactStart->getTimestamp())->toBe(7200);
});

it('Madrid 25-oct: un Carbon en UTC equivale al string con offset', function () {
    $i = evNormalizarDst(['start' => CarbonImmutable::parse('2026-10-25 00:30:00', 'UTC')], 'Europe/Madrid');

    expect((string) $i->range->start)->toBe('2026-10-25 02:30:00')
        ->and($i->range->seconds())->toBe(3600)
        ->and($i->exactEnd->format('c'))->toBe('2026-10-25T02:30:00+01:00');
});

it('Nueva York 3-nov: sin fin, 01:30−04:00 más 1 h da el mismo marker; el fin se re-expresa', function () {
    $i = evNormalizarDst(['start' => '2024-11-03T01:30:00-04:00'], 'America/New_York');

    expect((string) $i->range->start)->toBe('2024-11-03 01:30:00')
        ->and($i->hasEnd)->toBeFalse()
        ->and($i->exactEnd->format('c'))->toBe('2024-11-03T01:30:00-05:00')
        ->and($i->exactEnd->getTimestamp() - $i->exactStart->getTimestamp())->toBe(3600)
        ->and($i->range->seconds())->toBe(3600)
        ->and((string) $i->range->end)->toBe('2024-11-03 02:30:00');
});

it('Nueva York 3-nov: fin explícito 01:30−05:00 (una hora real después)', function () {
    $i = evNormalizarDst(['start' => '2024-11-03T01:30:00-04:00', 'end' => '2024-11-03T01:30:00-05:00'], 'America/New_York');

    expect($i->hasEnd)->toBeTrue()
        ->and($i->range->seconds())->toBe(3600)
        ->and((string) $i->range->end)->toBe('2024-11-03 02:30:00')
        ->and($i->exactEnd->getTimestamp() - $i->exactStart->getTimestamp())->toBe(3600);
});

it('Nueva York 3-nov: con instantes UTC (Carbon) el resultado es el mismo', function () {
    $i = evNormalizarDst([
        'start' => CarbonImmutable::parse('2024-11-03 05:30:00', 'UTC'),
        'end' => CarbonImmutable::parse('2024-11-03 06:30:00', 'UTC'),
    ], 'America/New_York');

    expect((string) $i->range->start)->toBe('2024-11-03 01:30:00')
        ->and($i->range->seconds())->toBe(3600)
        ->and($i->exactStart->format('c'))->toBe('2024-11-03T01:30:00-04:00')
        ->and($i->exactEnd->format('c'))->toBe('2024-11-03T01:30:00-05:00');
});

// ── Hueco de primavera ───────────────────────────────────────────────

it('Madrid 29-mar: sin fin, 01:30+01:00 más 1 h real son las 03:30+02:00 (la 02:30 no existe)', function () {
    $i = evNormalizarDst(['start' => '2026-03-29T01:30:00+01:00'], 'Europe/Madrid');

    expect((string) $i->range->start)->toBe('2026-03-29 01:30:00')
        ->and($i->exactEnd->format('c'))->toBe('2026-03-29T03:30:00+02:00')
        ->and($i->exactEnd->getTimestamp() - $i->exactStart->getTimestamp())->toBe(3600)
        ->and((string) $i->range->end)->toBe('2026-03-29 03:30:00')
        ->and($i->range->end->ts)->toBeGreaterThan($i->range->start->ts);
});

it('Madrid 29-mar: una hora de pared que no existe se acepta tal cual si es flotante', function () {
    $i = evNormalizarDst(['start' => '2026-03-29 02:30'], 'Europe/Madrid');

    // Sin instante no hay que resolver nada: la rejilla trabaja en markers.
    expect((string) $i->range->start)->toBe('2026-03-29 02:30:00')
        ->and((string) $i->range->end)->toBe('2026-03-29 03:30:00')
        ->and($i->exactStart)->toBeNull();
});

it('Madrid 29-mar: el fin flotante a través del hueco mide lo escrito, no lo transcurrido', function () {
    $i = evNormalizarDst(['start' => '2026-03-29 01:00', 'end' => '2026-03-29 04:00'], 'Europe/Madrid');

    expect($i->range->seconds())->toBe(3 * 3600);
});

it('Madrid 29-mar: dos instantes a cada lado del hueco', function () {
    $i = evNormalizarDst(['start' => '2026-03-29T01:30:00+01:00', 'end' => '2026-03-29T03:30:00+02:00'], 'Europe/Madrid');

    expect($i->hasEnd)->toBeTrue()
        ->and((string) $i->range->start)->toBe('2026-03-29 01:30:00')
        ->and((string) $i->range->end)->toBe('2026-03-29 03:30:00')
        ->and($i->exactEnd->getTimestamp() - $i->exactStart->getTimestamp())->toBe(3600);
});

it('Nueva York 10-mar: sin fin, 01:30−05:00 más 1 h real son las 03:30−04:00', function () {
    $i = evNormalizarDst(['start' => '2024-03-10T01:30:00-05:00'], 'America/New_York');

    expect((string) $i->range->start)->toBe('2024-03-10 01:30:00')
        ->and($i->exactEnd->format('c'))->toBe('2024-03-10T03:30:00-04:00')
        ->and((string) $i->range->end)->toBe('2024-03-10 03:30:00')
        ->and($i->exactEnd->getTimestamp() - $i->exactStart->getTimestamp())->toBe(3600);
});

it('Nueva York 10-mar: un Carbon justo en el salto', function () {
    // 07:00Z es el primer instante con −04:00: 03:00 de pared.
    $i = evNormalizarDst(['start' => CarbonImmutable::parse('2024-03-10 07:00:00', 'UTC')], 'America/New_York');

    expect((string) $i->range->start)->toBe('2024-03-10 03:00:00')
        ->and((string) $i->range->end)->toBe('2024-03-10 04:00:00');
});

// ── Barrido: el invariante en cada cuarto de hora de los días de cambio ──

it('range->end > range->start en cada cuarto de hora alrededor de un cambio de horario, con y sin fin', function (string $zona, string $dia) {
    // Desde tres horas antes de la medianoche del día de cambio hasta la mañana del siguiente.
    $inicio = CarbonImmutable::parse($dia.' 00:00:00', $zona)->subHours(3);
    $mal = [];

    for ($t = $inicio->getTimestamp(); $t < $inicio->getTimestamp() + 36 * 3600; $t += 900) {
        $start = CarbonImmutable::createFromTimestamp($t, $zona);

        foreach ([
            'sin fin' => ['start' => $start],
            'fin +45 min' => ['start' => $start, 'end' => $start->addSeconds(2700)],
            'fin +1 h' => ['start' => $start, 'end' => $start->addSeconds(3600)],
            'fin +90 min' => ['start' => $start, 'end' => $start->addSeconds(5400)],
            'fin inválido' => ['start' => $start, 'end' => $start->subSeconds(60)],
        ] as $caso => $args) {
            $i = evNormalizarDst($args, $zona);

            if ($i->range->end->ts <= $i->range->start->ts) {
                $mal[] = "{$start->format('c')} ({$caso}): {$i->range}";
            }

            // El instante, cuando existe, siempre dura lo que debe.
            $esperado = match ($caso) {
                'sin fin', 'fin inválido' => 3600,
                'fin +45 min' => 2700,
                'fin +1 h' => 3600,
                'fin +90 min' => 5400,
            };

            if ($i->exactEnd->getTimestamp() - $i->exactStart->getTimestamp() !== $esperado) {
                $mal[] = "{$start->format('c')} ({$caso}): duración real incorrecta";
            }
        }
    }

    expect($mal)->toBe([]);
})->with([
    ['Europe/Madrid', '2026-03-29'],
    ['Europe/Madrid', '2026-10-25'],
    ['America/New_York', '2024-03-10'],
    ['America/New_York', '2024-11-03'],
    ['Australia/Lord_Howe', '2026-10-04'],  // el cambio es de 30 min
    ['America/St_Johns', '2026-11-01'],     // offset con media hora
]);
