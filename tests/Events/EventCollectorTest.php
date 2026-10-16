<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\EventSource;
use KoreUi\Calendar\Events\Sources\ArraySource;
use KoreUi\Calendar\Events\Sources\CallbackSource;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

/** La semana del lunes 12 al domingo 18 de octubre de 2026 (fin exclusivo: el 19 a las 00:00). */
function evSemana(): DateRange
{
    return new DateRange(Marker::of(2026, 10, 12), Marker::of(2026, 10, 19));
}

function evColectorEn(string $zona = 'Europe/Madrid'): EventCollector
{
    $reloj = new WallClock($zona);

    return new EventCollector($reloj, new EventNormalizer($reloj));
}

/** @return list<string> Los ids de lo que sale de recoger las fuentes, ordenados para no depender del orden. */
function evIdsRecogidos(iterable $fuentes, ?DateRange $rango = null, string $zona = 'Europe/Madrid'): array
{
    $ids = array_map(fn (EventInstance $i) => (string) $i->event->id, evColectorEn($zona)->collect($fuentes, $rango ?? evSemana()));
    sort($ids);

    return $ids;
}

function evEventoDe(string $id, string|DateTimeInterface $start, string|DateTimeInterface|null $end = null, ?bool $allDay = null): CalendarEvent
{
    return CalendarEvent::make(id: $id, title: $id, start: $start, end: $end, allDay: $allDay);
}

// ── Qué le pide a las fuentes ────────────────────────────────────────

it('pide a cada fuente el rango en instantes reales de la zona de la vista', function () {
    $vistos = [];
    $fuente = new CallbackSource(function (CarbonImmutable $from, CarbonImmutable $to) use (&$vistos) {
        $vistos[] = [$from, $to];

        return [];
    });

    evColectorEn('Europe/Madrid')->collect([$fuente, $fuente], evSemana());

    // Madrid va en CEST (+02:00) hasta el 25-oct: la medianoche del 12 son las 22:00Z del 11.
    expect($vistos)->toHaveCount(2)
        ->and($vistos[0][0]->getTimestamp())->toBe(CarbonImmutable::parse('2026-10-11T22:00:00Z')->getTimestamp())
        ->and($vistos[0][1]->getTimestamp())->toBe(CarbonImmutable::parse('2026-10-18T22:00:00Z')->getTimestamp())
        ->and($vistos[0][0]->getTimezone()->getName())->toBe('Europe/Madrid');
});

it('la vista que cruza el cambio de horario pide el instante correcto en cada extremo', function () {
    // Semana del 19 al 26 de octubre: empieza en CEST y acaba en CET.
    $rango = new DateRange(Marker::of(2026, 10, 19), Marker::of(2026, 10, 26));
    $visto = null;

    evColectorEn('Europe/Madrid')->collect([new CallbackSource(function ($from, $to) use (&$visto) {
        $visto = [$from, $to];

        return [];
    })], $rango);

    expect($visto[0]->format('c'))->toBe('2026-10-19T00:00:00+02:00')
        ->and($visto[1]->format('c'))->toBe('2026-10-26T00:00:00+01:00');
});

it('sin fuentes devuelve una lista vacía', function () {
    expect(evColectorEn()->collect([], evSemana()))->toBe([]);
});

it('acepta las fuentes como cualquier iterable, también un generador', function () {
    $fuentes = (function () {
        yield new ArraySource([evEventoDe('a', '2026-10-13 10:00')]);
        yield new ArraySource([evEventoDe('b', '2026-10-14 10:00')]);
    })();

    expect(evIdsRecogidos($fuentes))->toBe(['a', 'b']);
});

it('junta los eventos de varias fuentes', function () {
    $una = new ArraySource([evEventoDe('a', '2026-10-13 10:00'), evEventoDe('b', '2026-10-14 10:00')]);
    $otra = new CallbackSource(fn () => [['id' => 'c', 'title' => 'c', 'start' => '2026-10-15 10:00']]);

    expect(evIdsRecogidos([$una, $otra]))->toBe(['a', 'b', 'c']);
});

it('no ordena: respeta el orden de las fuentes y de sus eventos', function () {
    $fuente = new ArraySource([evEventoDe('tarde', '2026-10-15 10:00'), evEventoDe('pronto', '2026-10-13 10:00')]);

    $ids = array_map(fn ($i) => $i->event->id, evColectorEn()->collect([$fuente], evSemana()));

    expect($ids)->toBe(['tarde', 'pronto']);
});

it('devuelve EventInstance ya normalizadas', function () {
    $out = evColectorEn()->collect([new ArraySource([evEventoDe('a', '2026-10-13 10:00')])], evSemana());

    expect($out)->toHaveCount(1)
        ->and($out[0])->toBeInstanceOf(EventInstance::class)
        ->and((string) $out[0]->range->end)->toBe('2026-10-13 11:00:00');
});

// ── Filtro por rango: bordes semiabiertos ────────────────────────────

it('filtra por intersección con el rango', function (string $start, ?string $end, bool $entra) {
    $out = evIdsRecogidos([new ArraySource([evEventoDe('e', $start, $end)])]);

    expect($out)->toBe($entra ? ['e'] : []);
})->with([
    'dentro' => ['2026-10-14 10:00', '2026-10-14 11:00', true],
    'mucho antes' => ['2026-09-01 10:00', '2026-09-01 11:00', false],
    'mucho después' => ['2026-11-01 10:00', '2026-11-01 11:00', false],
    'empieza antes y acaba dentro' => ['2026-10-11 20:00', '2026-10-12 08:00', true],
    'empieza dentro y acaba después' => ['2026-10-18 20:00', '2026-10-19 08:00', true],
    'lo contiene entero' => ['2026-10-01 00:00', '2026-11-01 00:00', true],
    'acaba JUSTO cuando empieza el rango: no entra' => ['2026-10-11 22:00', '2026-10-12 00:00', false],
    'acaba un segundo después del inicio: entra' => ['2026-10-11 22:00', '2026-10-12 00:00:01', true],
    'empieza JUSTO cuando acaba el rango: no entra' => ['2026-10-19 00:00', '2026-10-19 01:00', false],
    'empieza un segundo antes del fin: entra' => ['2026-10-18 23:59:59', '2026-10-19 01:00', true],
    'empieza justo al inicio del rango: entra' => ['2026-10-12 00:00', '2026-10-12 01:00', true],
    'acaba justo al fin del rango: entra' => ['2026-10-18 23:00', '2026-10-19 00:00', true],
]);

it('un evento sin fin dura el valor por defecto a efectos del filtro', function (string $start, bool $entra) {
    expect(evIdsRecogidos([new ArraySource([evEventoDe('e', $start)])]))->toBe($entra ? ['e'] : []);
})->with([
    'empieza 30 min antes: su hora por defecto cruza el inicio' => ['2026-10-11 23:30', true],
    'empieza 1 h antes: acaba justo en el inicio' => ['2026-10-11 23:00', false],
    'empieza 2 h antes' => ['2026-10-11 22:00', false],
    'empieza a la última hora' => ['2026-10-18 23:00', true],
    'empieza justo al acabar el rango' => ['2026-10-19 00:00', false],
]);

it('el fin de un día completo es exclusivo también al filtrar', function (string $start, ?string $end, bool $entra) {
    expect(evIdsRecogidos([new ArraySource([evEventoDe('e', $start, $end)])]))->toBe($entra ? ['e'] : []);
})->with([
    'el día anterior entero (11 → 12)' => ['2026-10-11', '2026-10-12', false],
    'el día anterior sin fin' => ['2026-10-11', null, false],
    'dos días hasta el 13 (11 → 13)' => ['2026-10-11', '2026-10-13', true],
    'el primer día' => ['2026-10-12', null, true],
    'el último día' => ['2026-10-18', null, true],
    'el día siguiente al rango' => ['2026-10-19', null, false],
    'termina el 19 (cubre el 18)' => ['2026-10-17', '2026-10-19', true],
    'empieza el 19 y dura 2 días' => ['2026-10-19', '2026-10-21', false],
]);

it('un evento de 23:30 UTC cae en el día siguiente de Madrid y entra o no según la vista', function () {
    $evento = evEventoDe('e', CarbonImmutable::parse('2026-10-11 23:30:00', 'UTC'), CarbonImmutable::parse('2026-10-11 23:50:00', 'UTC'));
    $fuente = new ArraySource([$evento]);

    // En Madrid son las 01:30-01:50 del lunes 12: dentro de la semana.
    expect(evIdsRecogidos([$fuente], zona: 'Europe/Madrid'))->toBe(['e']);

    // En UTC son las 23:30-23:50 del domingo 11: fuera.
    expect(evIdsRecogidos([$fuente], zona: 'UTC'))->toBe([]);

    // En Nueva York (−04:00) son las 19:30 del domingo 11: también fuera.
    expect(evIdsRecogidos([$fuente], zona: 'America/New_York'))->toBe([]);
});

it('el borde de la zona de la vista: 21:30Z–22:00Z es 23:30–00:00 en Madrid y no entra; 22:00Z–22:30Z sí', function () {
    $antes = evEventoDe('antes', '2026-10-11T21:30:00Z', '2026-10-11T22:00:00Z');
    $despues = evEventoDe('despues', '2026-10-11T22:00:00Z', '2026-10-11T22:30:00Z');

    expect(evIdsRecogidos([new ArraySource([$antes, $despues])]))->toBe(['despues']);
});

it('un evento de varios días entra con su rango completo, sin recortar', function () {
    $out = evColectorEn()->collect([new ArraySource([evEventoDe('e', '2026-10-10', '2026-10-14')])], evSemana());

    expect((string) $out[0]->range->start)->toBe('2026-10-10 00:00:00')
        ->and((string) $out[0]->range->end)->toBe('2026-10-14 00:00:00');
});

it('el mismo evento en dos fuentes sale dos veces (deduplicar no es cosa del colector)', function () {
    $e = evEventoDe('e', '2026-10-13 10:00');

    expect(evIdsRecogidos([new ArraySource([$e]), new ArraySource([$e])]))->toBe(['e', 'e']);
});

it('un rango vacío no recoge nada', function () {
    $vacio = new DateRange(Marker::of(2026, 10, 13, 10), Marker::of(2026, 10, 13, 10));

    expect(evIdsRecogidos([new ArraySource([evEventoDe('e', '2026-10-13 10:00')])], $vacio))->toBe([]);
});

// ── Arrays y errores ─────────────────────────────────────────────────

it('los arrays de una fuente pasan por CalendarEvent::fromArray', function () {
    $fuente = new CallbackSource(fn () => [
        ['id' => 'a', 'title' => 'a', 'start' => '2026-10-13 10:00', 'resourceId' => 'sala-1', 'color' => 'primary'],
    ]);

    $out = evColectorEn()->collect([$fuente], evSemana());

    expect($out[0]->event->resourceIds)->toBe(['sala-1'])
        ->and($out[0]->event->color)->toBe('primary');
});

it('un array con una clave desconocida lanza e indica la fuente', function () {
    $fuente = new CallbackSource(fn () => [['id' => 'a', 'title' => 'a', 'start' => '2026-10-13', 'colour' => 'red']]);

    try {
        evColectorEn()->collect([$fuente], evSemana());
        $this->fail('Debía lanzar.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain(CallbackSource::class)->toContain('«colour»')
            ->and($e->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
    }
});

it('una fuente que devuelve algo que no es evento ni array lanza con la clase de la fuente', function (mixed $malo, string $tipo) {
    $fuente = new ArraySource([evEventoDe('ok', '2026-10-13'), $malo]);

    try {
        evColectorEn()->collect([$fuente], evSemana());
        $this->fail('Debía lanzar.');
    } catch (UnexpectedValueException $e) {
        expect($e->getMessage())->toContain(ArraySource::class)->toContain("devolvió {$tipo}");
    }
})->with([
    'null' => [null, 'null'],
    'string' => ['2026-10-13', 'string'],
    'int' => [7, 'int'],
    'objeto ajeno' => [new stdClass, 'stdClass'],
]);

it('algo en la lista de fuentes que no es una EventSource lanza', function () {
    expect(fn () => evColectorEn()->collect([new ArraySource([]), 'no soy una fuente'], evSemana()))
        ->toThrow(UnexpectedValueException::class, EventSource::class.' y recibió string');
});

it('una fecha inválida en un evento lanza con el id del evento', function () {
    $fuente = new ArraySource([evEventoDe('roto', 'mañana')]);

    expect(fn () => evColectorEn()->collect([$fuente], evSemana()))
        ->toThrow(InvalidArgumentException::class, 'Evento «roto»');
});

// ── Series y excepciones: cada una dentro de su fuente ───────────────

/** Los títulos de lo recogido y cuántas veces sale cada uno. */
function evTitulosRecogidos(iterable $fuentes): array
{
    $cuentas = array_count_values(array_map(fn (EventInstance $i) => $i->event->title, evColectorEn()->collect($fuentes, evSemana())));
    ksort($cuentas);

    return $cuentas;
}

function evSerie(string|int $id, string $titulo, string $inicio = '2026-10-12 09:00:00', string $regla = 'FREQ=DAILY;COUNT=7'): CalendarEvent
{
    return CalendarEvent::make(id: $id, title: $titulo, start: $inicio, end: date('Y-m-d H:i:s', strtotime($inicio) + 3600), rrule: $regla);
}

function evExcepcion(string|int $serie, string $titulo, string $ocurrencia, string $nuevoInicio = '2026-10-14 15:00:00'): CalendarEvent
{
    return CalendarEvent::make(
        id: "{$serie}#{$ocurrencia}", title: $titulo, start: $nuevoInicio, end: date('Y-m-d H:i:s', strtotime($nuevoInicio) + 3600),
        recurrenceOf: $serie, recurrenceId: $ocurrencia,
    );
}

it('dos series con el mismo id en fuentes distintas son dos series: una no sustituye a la otra', function () {
    // La base de datos tiene la serie 5; un `.ics` ajeno trae un `UID:5` que se convierte en el mismo id.
    $propia = new ArraySource([evSerie(5, 'Reunión confidencial')]);
    $ajena = new ArraySource([evSerie(5, 'Impostora', '2026-10-13 20:00:00', 'FREQ=DAILY;COUNT=1')]);

    expect(evTitulosRecogidos([$propia, $ajena]))->toBe(['Impostora' => 1, 'Reunión confidencial' => 7])
        ->and(evTitulosRecogidos([$ajena, $propia]))->toBe(['Impostora' => 1, 'Reunión confidencial' => 7]);
});

it('una excepción de otra fuente no oculta las ocurrencias de una serie con el mismo id', function () {
    $propia = new ArraySource([evSerie(5, 'Reunión confidencial')]);
    $ajena = new ArraySource([
        evExcepcion(5, 'Oculta 1', '2026-10-14 09:00:00', '2030-01-01 09:00:00'),
        evExcepcion(5, 'Oculta 2', '2026-10-15 09:00:00', '2030-01-01 09:00:00'),
    ]);

    // Siguen las siete: lo que trae la otra fuente cae fuera del rango y no reclama ninguna.
    expect(evTitulosRecogidos([$propia, $ajena]))->toBe(['Reunión confidencial' => 7])
        ->and(evTitulosRecogidos([$ajena, $propia]))->toBe(['Reunión confidencial' => 7]);
});

it('una excepción sí sustituye la ocurrencia de la serie de su propia fuente, llegue antes o después', function () {
    $serie = evSerie(5, 'Serie');
    $excepcion = evExcepcion(5, 'Cambiada', '2026-10-14 09:00:00');

    // Siete menos la sustituida, más la cambiada.
    expect(evTitulosRecogidos([new ArraySource([$serie, $excepcion])]))->toBe(['Cambiada' => 1, 'Serie' => 6])
        ->and(evTitulosRecogidos([new ArraySource([$excepcion, $serie])]))->toBe(['Cambiada' => 1, 'Serie' => 6]);
});

it('una excepción de una fuente solo sustituye a la serie de esa fuente aunque otra tenga el mismo id', function () {
    $propia = new ArraySource([evSerie(5, 'Propia')]);
    $ajena = new ArraySource([evSerie(5, 'Ajena'), evExcepcion(5, 'Cambiada', '2026-10-14 09:00:00')]);

    // La excepción reclama el 14 de SU serie (la ajena); la propia conserva sus siete.
    expect(evTitulosRecogidos([$propia, $ajena]))->toBe(['Ajena' => 6, 'Cambiada' => 1, 'Propia' => 7]);
});
