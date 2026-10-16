<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Time\WallClock;

function evNormalizadorEn(string $zona = 'Europe/Madrid', int $timed = 3600, int $dias = 1): EventNormalizer
{
    return new EventNormalizer(new WallClock($zona), $timed, $dias);
}

/** Atajo: normaliza un evento con los argumentos dados. */
function evNormalizar(array $args, string $zona = 'Europe/Madrid', int $timed = 3600, int $dias = 1)
{
    return evNormalizadorEn($zona, $timed, $dias)->normalize(CalendarEvent::make(...['id' => 1, 'title' => 't', ...$args]));
}

// ── Inferencia de día completo ───────────────────────────────────────

it('infiere allDay solo cuando inicio (y fin, si lo hay) son fechas sin hora', function (mixed $start, mixed $end, ?bool $allDay, bool $esperado) {
    expect(evNormalizar(['start' => $start, 'end' => $end, 'allDay' => $allDay])->allDay)->toBe($esperado);
})->with([
    'fecha sola' => ['2026-10-16', null, null, true],
    'fecha y fecha' => ['2026-10-16', '2026-10-18', null, true],
    'inicio con hora' => ['2026-10-16 10:00', null, null, false],
    'inicio con T y hora' => ['2026-10-16T10:00:00', null, null, false],
    'inicio con offset' => ['2026-10-16T10:00:00+02:00', null, null, false],
    'fecha pero fin con hora' => ['2026-10-16', '2026-10-17 10:00', null, false],
    'inicio con hora y fin fecha' => ['2026-10-16 10:00', '2026-10-17', null, false],
    'un Carbon nunca se infiere, ni a medianoche' => [CarbonImmutable::parse('2026-10-16 00:00', 'Europe/Madrid'), null, null, false],
    'fecha y fin Carbon' => ['2026-10-16', CarbonImmutable::parse('2026-10-18 00:00'), null, false],
    'un DateTime tampoco' => [new DateTimeImmutable('2026-10-16'), null, null, false],
    'explícito true manda sobre la hora' => ['2026-10-16 10:00', null, true, true],
    'explícito true con Carbon' => [CarbonImmutable::parse('2026-10-16 10:00'), null, true, true],
    'explícito false manda sobre la fecha' => ['2026-10-16', null, false, false],
    'explícito false con fecha y fecha' => ['2026-10-16', '2026-10-18', false, false],
]);

it('una fecha sola con allDay false es un evento con hora a las 00:00', function () {
    $i = evNormalizar(['start' => '2026-10-16', 'allDay' => false]);

    expect($i->allDay)->toBeFalse()
        ->and((string) $i->range->start)->toBe('2026-10-16 00:00:00')
        ->and((string) $i->range->end)->toBe('2026-10-16 01:00:00');
});

// ── Día completo ─────────────────────────────────────────────────────

it('el fin de un día completo es exclusivo: 3-sep → 5-sep cubre el 3 y el 4', function () {
    $i = evNormalizar(['start' => '2018-09-03', 'end' => '2018-09-05']);

    expect($i->allDay)->toBeTrue()
        ->and($i->hasEnd)->toBeTrue()
        ->and((string) $i->range->start)->toBe('2018-09-03 00:00:00')
        ->and((string) $i->range->end)->toBe('2018-09-05 00:00:00')
        ->and(array_map(fn ($d) => $d->toDateString(), $i->range->eachDay()))->toBe(['2018-09-03', '2018-09-04']);
});

it('un día completo no tiene instantes exactos', function () {
    $i = evNormalizar(['start' => '2026-10-16', 'end' => '2026-10-17']);

    expect($i->exactStart)->toBeNull()->and($i->exactEnd)->toBeNull();
});

it('un día completo sin fin dura el valor por defecto (1 día) y recuerda que no tenía fin', function () {
    $i = evNormalizar(['start' => '2026-10-16']);

    expect($i->hasEnd)->toBeFalse()
        ->and((string) $i->range->start)->toBe('2026-10-16 00:00:00')
        ->and((string) $i->range->end)->toBe('2026-10-17 00:00:00');
});

it('la duración por defecto de un día completo es configurable', function () {
    $i = evNormalizar(['start' => '2026-10-16'], dias: 3);

    expect((string) $i->range->end)->toBe('2026-10-19 00:00:00');
});

it('un fin de día completo igual o anterior al inicio no descarta el evento', function (string $end) {
    $i = evNormalizar(['start' => '2026-10-16', 'end' => $end]);

    expect($i->hasEnd)->toBeFalse()
        ->and((string) $i->range->start)->toBe('2026-10-16 00:00:00')
        ->and((string) $i->range->end)->toBe('2026-10-17 00:00:00');
})->with(['mismo día' => '2026-10-16', 'día anterior' => '2026-10-15', 'mucho antes' => '2025-01-01']);

it('un fin con hora en el mismo día de un día completo explícito también es inválido', function () {
    // Se trunca a las 00:00 y el rango quedaría vacío.
    $i = evNormalizar(['start' => '2026-10-16', 'end' => '2026-10-16 18:00', 'allDay' => true]);

    expect($i->hasEnd)->toBeFalse()->and((string) $i->range->end)->toBe('2026-10-17 00:00:00');
});

it('un día completo trunca la hora al inicio del día, en inicio y en fin', function () {
    $i = evNormalizar(['start' => '2026-10-16 15:45', 'end' => '2026-10-18 09:30', 'allDay' => true]);

    expect((string) $i->range->start)->toBe('2026-10-16 00:00:00')
        ->and((string) $i->range->end)->toBe('2026-10-18 00:00:00');
});

it('las fechas de un día completo son de calendario: no se convierten de zona', function (string $zona, mixed $start) {
    // 23:30 UTC del 16 es el 17 en Madrid y 08:30 del 17 en Tokio. Un evento de
    // día completo el 16 sigue siendo el 16 en todas partes.
    $i = evNormalizar(['start' => $start instanceof Closure ? $start() : $start, 'allDay' => true], $zona);

    expect($i->range->start->toDateString())->toBe('2026-10-16');
})->with([
    'Carbon en UTC, vista en Madrid' => ['Europe/Madrid', fn () => CarbonImmutable::parse('2026-10-16 23:30', 'UTC')],
    'Carbon en UTC, vista en Tokio' => ['Asia/Tokyo', fn () => CarbonImmutable::parse('2026-10-16 23:30', 'UTC')],
    'Carbon en Tokio, vista en UTC' => ['UTC', fn () => CarbonImmutable::parse('2026-10-16 00:30', 'Asia/Tokyo')],
    'Carbon en Tokio, vista en Nueva York' => ['America/New_York', fn () => CarbonImmutable::parse('2026-10-16 00:30', 'Asia/Tokyo')],
    'string con Z, vista en Los Ángeles' => ['America/Los_Angeles', '2026-10-16T00:00:00.000000Z'],
    'string con offset, vista en Auckland' => ['Pacific/Auckland', '2026-10-16T23:30:00-05:00'],
    'DateTime de PHP' => ['America/Santiago', fn () => new DateTimeImmutable('2026-10-16 23:59:59', new DateTimeZone('Asia/Kolkata'))],
]);

it('un día completo dura 24 h en marker incluso el día del cambio de horario', function (string $zona, string $dia) {
    $i = evNormalizar(['start' => $dia], $zona);

    expect($i->range->seconds())->toBe(86_400)
        ->and($i->range->start->isStartOfDay())->toBeTrue();
})->with([
    ['Europe/Madrid', '2026-03-29'],
    ['Europe/Madrid', '2026-10-25'],
    ['America/New_York', '2024-03-10'],
    ['America/New_York', '2024-11-03'],
    ['America/Santiago', '2026-09-06'],
]);

// ── Con hora: floating, offset, instante ─────────────────────────────

it('un string sin offset es hora de pared de la vista y no tiene instante', function () {
    $i = evNormalizar(['start' => '2026-10-16 10:00', 'end' => '2026-10-16T11:30:00']);

    expect($i->allDay)->toBeFalse()
        ->and($i->hasEnd)->toBeTrue()
        ->and((string) $i->range->start)->toBe('2026-10-16 10:00:00')
        ->and((string) $i->range->end)->toBe('2026-10-16 11:30:00')
        ->and($i->exactStart)->toBeNull()
        ->and($i->exactEnd)->toBeNull();
});

it('la hora flotante no depende de la zona de la vista', function (string $zona) {
    expect((string) evNormalizar(['start' => '2026-10-16 10:00'], $zona)->range->start)->toBe('2026-10-16 10:00:00');
})->with(['UTC', 'Europe/Madrid', 'Asia/Tokyo', 'America/Los_Angeles']);

it('un string con offset conserva el instante y se pasa a hora de pared de la vista', function (string $start, string $zona, string $marker) {
    $i = evNormalizar(['start' => $start, 'end' => CarbonImmutable::parse($start)->addHour()->toIso8601String()], $zona);

    expect((string) $i->range->start)->toBe($marker)
        ->and($i->exactStart)->toBeInstanceOf(CarbonImmutable::class)
        ->and($i->exactStart->getTimestamp())->toBe(CarbonImmutable::parse($start)->getTimestamp())
        ->and($i->exactStart->getTimezone()->getName())->toBe($zona)
        ->and($i->exactEnd->getTimestamp() - $i->exactStart->getTimestamp())->toBe(3600);
})->with([
    'Z, vista en Madrid (CEST)' => ['2026-10-16T08:00:00Z', 'Europe/Madrid', '2026-10-16 10:00:00'],
    'Z, vista en UTC' => ['2026-10-16T08:00:00Z', 'UTC', '2026-10-16 08:00:00'],
    '+02:00, vista en UTC' => ['2026-10-16T10:00:00+02:00', 'UTC', '2026-10-16 08:00:00'],
    '+02:00, vista en Madrid' => ['2026-10-16T10:00:00+02:00', 'Europe/Madrid', '2026-10-16 10:00:00'],
    '-0500 sin dos puntos, vista en Madrid' => ['2026-10-16T10:00:00-0500', 'Europe/Madrid', '2026-10-16 17:00:00'],
    'con fracción y Z' => ['2026-10-16T08:00:00.250Z', 'Europe/Madrid', '2026-10-16 10:00:00'],
    'con espacio en vez de T' => ['2026-10-16 08:00:00+00:00', 'Europe/Madrid', '2026-10-16 10:00:00'],
]);

it('un Carbon en otra zona se lleva a la hora de pared de la vista: 23:30 UTC es el día siguiente en Madrid', function () {
    $i = evNormalizar(['start' => CarbonImmutable::parse('2026-10-16 23:30:00', 'UTC'), 'end' => CarbonImmutable::parse('2026-10-17 00:30:00', 'UTC')]);

    expect((string) $i->range->start)->toBe('2026-10-17 01:30:00')
        ->and((string) $i->range->end)->toBe('2026-10-17 02:30:00')
        ->and($i->range->start->toDateString())->toBe('2026-10-17')
        ->and($i->exactStart->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($i->exactStart->format('c'))->toBe('2026-10-17T01:30:00+02:00');
});

it('un DateTimeImmutable de PHP (sin Carbon) también es un instante', function () {
    $i = evNormalizar(['start' => new DateTimeImmutable('2026-10-16 08:00:00', new DateTimeZone('UTC')), 'end' => new DateTime('2026-10-16 09:00:00 UTC')]);

    expect((string) $i->range->start)->toBe('2026-10-16 10:00:00')
        ->and($i->exactStart)->toBeInstanceOf(CarbonImmutable::class)
        ->and($i->exactEnd)->toBeInstanceOf(CarbonImmutable::class);
});

it('el instante de salida no comparte estado con la entrada', function () {
    $entrada = CarbonImmutable::parse('2026-10-16 08:00', 'UTC');
    $i = evNormalizar(['start' => $entrada]);

    expect($entrada->getTimezone()->getName())->toBe('UTC')
        ->and($i->exactStart)->not->toBe($entrada);
});

it('inicio flotante con fin con instante: se comparan markers y no hay instante de inicio', function () {
    $i = evNormalizar(['start' => '2026-10-16 10:00', 'end' => '2026-10-16T09:30:00Z']);

    // 09:30Z son las 11:30 en Madrid.
    expect((string) $i->range->end)->toBe('2026-10-16 11:30:00')
        ->and($i->hasEnd)->toBeTrue()
        ->and($i->exactStart)->toBeNull()
        ->and($i->exactEnd)->not->toBeNull();
});

it('inicio con instante y fin flotante: el fin queda en hora de pared', function () {
    $i = evNormalizar(['start' => '2026-10-16T08:00:00Z', 'end' => '2026-10-16 12:00']);

    expect((string) $i->range->start)->toBe('2026-10-16 10:00:00')
        ->and((string) $i->range->end)->toBe('2026-10-16 12:00:00')
        ->and($i->exactStart)->not->toBeNull()
        ->and($i->exactEnd)->toBeNull();
});

it('un evento con fin entre zonas se valida por instante, no por la hora escrita', function () {
    // Empieza a las 10:00+02:00 (08:00Z) y acaba a las 07:30-05:00 (12:30Z): la hora
    // escrita del fin es anterior, pero el instante es 4 h 30 min después.
    $i = evNormalizar(['start' => '2026-10-16T10:00:00+02:00', 'end' => '2026-10-16T07:30:00-05:00']);

    expect($i->hasEnd)->toBeTrue()
        ->and((string) $i->range->end)->toBe('2026-10-16 14:30:00');
});

// ── Fin ausente o inválido (con hora) ────────────────────────────────

it('sin fin: duración por defecto de 1 h, hasEnd false, y se conserva el evento', function () {
    $i = evNormalizar(['start' => '2026-10-16 10:00']);

    expect($i->hasEnd)->toBeFalse()
        ->and((string) $i->range->end)->toBe('2026-10-16 11:00:00')
        ->and($i->exactEnd)->toBeNull();
});

it('la duración por defecto de un evento con hora es configurable', function () {
    expect((string) evNormalizar(['start' => '2026-10-16 10:00'], timed: 1800)->range->end)->toBe('2026-10-16 10:30:00');
    expect((string) evNormalizar(['start' => '2026-10-16 23:30'], timed: 7200)->range->end)->toBe('2026-10-17 01:30:00');
});

it('sin fin y con instante de inicio, el instante de fin es inicio + duración por defecto', function () {
    $i = evNormalizar(['start' => '2026-10-16T08:00:00Z'], timed: 5400);

    expect($i->hasEnd)->toBeFalse()
        ->and($i->exactEnd->getTimestamp() - $i->exactStart->getTimestamp())->toBe(5400)
        ->and((string) $i->range->end)->toBe('2026-10-16 11:30:00')
        ->and($i->exactEnd->getTimezone()->getName())->toBe('Europe/Madrid');
});

it('un fin igual o anterior al inicio no descarta el evento: duración por defecto', function (string $end) {
    $i = evNormalizar(['start' => '2026-10-16 10:00', 'end' => $end]);

    expect($i->hasEnd)->toBeFalse()
        ->and((string) $i->range->start)->toBe('2026-10-16 10:00:00')
        ->and((string) $i->range->end)->toBe('2026-10-16 11:00:00');
})->with(['igual' => '2026-10-16 10:00', 'un minuto antes' => '2026-10-16 09:59', 'días antes' => '2026-10-10 10:00']);

it('un fin inválido con instantes también cae a la duración por defecto, con instante de fin recalculado', function () {
    $i = evNormalizar(['start' => '2026-10-16T08:00:00Z', 'end' => '2026-10-16T07:00:00Z']);

    expect($i->hasEnd)->toBeFalse()
        ->and($i->exactEnd->format('c'))->toBe('2026-10-16T11:00:00+02:00')
        ->and((string) $i->range->end)->toBe('2026-10-16 11:00:00');
});

it('un fin mismo instante y distinta zona es inválido (cero de duración)', function () {
    $i = evNormalizar(['start' => '2026-10-16T08:00:00Z', 'end' => '2026-10-16T10:00:00+02:00']);

    expect($i->hasEnd)->toBeFalse();
});

it('un fin que cae en el mismo segundo que el inicio pero es posterior dura al menos 1 s', function () {
    $i = evNormalizar(['start' => '2026-10-16T08:00:00.000Z', 'end' => '2026-10-16T08:00:00.500Z']);

    expect($i->hasEnd)->toBeTrue()
        ->and($i->range->end->ts)->toBeGreaterThan($i->range->start->ts)
        ->and($i->range->seconds())->toBe(1);
});

// ── instanceId ───────────────────────────────────────────────────────

it('instanceId es el id del evento y el inicio en marker', function () {
    $i = evNormalizar(['id' => 'abc', 'start' => '2026-10-16 10:00']);

    expect($i->instanceId)->toBe('abc:'.$i->range->start->ts)
        ->and($i->event->id)->toBe('abc');
});

it('el mismo evento normalizado dos veces da el mismo instanceId', function () {
    $e = CalendarEvent::make(id: 5, title: 't', start: '2026-10-16T08:00:00Z');
    $n = evNormalizadorEn();

    expect($n->normalize($e)->instanceId)->toBe($n->normalize($e)->instanceId);
});

// ── Fechas inválidas ─────────────────────────────────────────────────

it('un string que no es ISO 8601 lanza con el id del evento y el campo', function (string $valor) {
    try {
        evNormalizar(['id' => 'ev-9', 'start' => $valor]);
        $this->fail('Debía lanzar.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('Evento «ev-9»')->toContain('`start`')->toContain("«{$valor}»")->toContain('ISO 8601');
    }
})->with([
    'palabra' => 'mañana',
    'relativo de PHP' => 'next monday',
    'mes 13' => '2026-13-01',
    'día inexistente' => '2026-02-30',
    'hora 25' => '2026-10-16 25:00',
    'minuto 60' => '2026-10-16 10:60',
    'sin ceros' => '2026-1-5',
    'europeo' => '16/10/2026',
    'basura tras la fecha' => '2026-10-16 10:00 tarde',
    'offset imposible' => '2026-10-16T10:00:00+99:99',
]);

it('lo mismo para el fin y para un día completo', function () {
    expect(fn () => evNormalizar(['start' => '2026-10-16 10:00', 'end' => 'pronto']))
        ->toThrow(InvalidArgumentException::class, '`end` «pronto»');

    expect(fn () => evNormalizar(['start' => '2026-02-30', 'allDay' => true]))
        ->toThrow(InvalidArgumentException::class, '`start` «2026-02-30»');

    expect(fn () => evNormalizar(['start' => '2026-10-16', 'end' => '2026-10-xx']))
        ->toThrow(InvalidArgumentException::class, '`end`');
});

it('el 29 de febrero solo existe en año bisiesto', function () {
    expect((string) evNormalizar(['start' => '2028-02-29'])->range->start)->toBe('2028-02-29 00:00:00');
    expect(fn () => evNormalizar(['start' => '2026-02-29']))->toThrow(InvalidArgumentException::class);
});

it('rechaza duraciones por defecto que no sean positivas', function () {
    expect(fn () => new EventNormalizer(new WallClock('UTC'), 0))->toThrow(InvalidArgumentException::class, 'al menos 1 segundo');
    expect(fn () => new EventNormalizer(new WallClock('UTC'), 3600, 0))->toThrow(InvalidArgumentException::class, 'al menos 1 día');
});
