<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\Sources\ArraySource;
use KoreUi\Calendar\Recurrence\OccurrenceBudget;
use KoreUi\Calendar\Recurrence\RecurrenceEditor;
use KoreUi\Calendar\Recurrence\RecurrenceExpander;
use KoreUi\Calendar\Recurrence\RuleScanner;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;
use RRule\RRule;

/*
| Las reglas hostiles no tumban la página (auditoría de seguridad, H1).
|
| php-rrule recorre y guarda en caché TODAS las ocurrencias desde DTSTART, también las
| anteriores a la vista: `DTSTART:20200101` con `FREQ=MINUTELY` agotaba 256 MB en el primer
| render, y veinte series así eran 5 s y 475 MB. Aquí se comprueba, con límites de tiempo y de
| memoria, que ya no pasa, y que adelantar el inicio no cambia ninguna ocurrencia.
*/

/**
 * Ejecuta `$fn` y devuelve [resultado, segundos, MB de pico de memoria sobre el punto de partida].
 *
 * @return array{0: mixed, 1: float, 2: float}
 */
function rlMeasure(callable $fn): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $start = hrtime(true);

    $result = $fn();

    return [$result, (hrtime(true) - $start) / 1e9, (memory_get_peak_usage() - $base) / 1048576];
}

/**
 * Las instancias de unos eventos en un rango. El expansor pide un día de margen a cada lado, así que
 * una regla de alta frecuencia necesita un `$max` que lo cubra para que salga algo dentro de la vista.
 *
 * @param  list<array<string, mixed>>  $events
 */
function rlCollect(array $events, string $from, string $to, string $zone = 'UTC', ?int $max = null): array
{
    $clock = new WallClock($zone);

    return (new EventCollector($clock, new EventNormalizer($clock), $max))
        ->collect([new ArraySource($events)], new DateRange(Marker::parse($from), Marker::parse($to)));
}

function rlSeries(string $id, string $start, string $rrule): array
{
    return ['id' => $id, 'title' => $id, 'start' => $start, 'end' => (new DateTimeImmutable($start))->modify('+1 minute')->format('Y-m-d H:i:s'), 'rrule' => $rrule];
}

// ── H1: DTSTART lejano con una frecuencia alta ──────────────────────────

it('FREQ=MINUTELY desde 2020 no recorre tres millones de ocurrencias', function () {
    [$instances, $seconds, $memory] = rlMeasure(fn () => rlCollect([rlSeries('m', '2020-01-01 00:00:00', 'FREQ=MINUTELY')], '2026-10-12', '2026-10-13', 'UTC', 5000));

    // Un día de minutos, y el primero es el del 12-oct a las 00:00 (la ocurrencia de las 23:59 del 11 acaba a las 00:00).
    expect($instances)->toHaveCount(1440)
        ->and(gmdate('Y-m-d H:i', $instances[0]->range->start->ts))->toBe('2026-10-12 00:00')
        ->and($memory)->toBeLessThan(24)
        ->and($seconds)->toBeLessThan(1.5);
});

it('FREQ=SECONDLY desde el principio del año tampoco', function () {
    [$instances, $seconds, $memory] = rlMeasure(fn () => rlCollect([rlSeries('s', '2026-01-01 00:00:00', 'FREQ=SECONDLY;INTERVAL=60')], '2026-10-12 00:00', '2026-10-12 01:00', 'UTC', 5000));

    // Sin adelantar el inicio eran 24 millones de segundos y 8 GB de caché.
    expect(count($instances))->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(61)
        ->and($memory)->toBeLessThan(24)
        ->and($seconds)->toBeLessThan(1.5);
});

it('adelantar el inicio no cambia la ocurrencia que cae justo en el borde de la vista', function () {
    // Cada 7 minutos desde hace 12 días (el cálculo a pelo de php-rrule tiene que seguir siendo barato):
    // la vista empieza en una ocurrencia exacta. Con un paso de margen, ni se pierde ni se duplica.
    $start = new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('UTC'));
    $edge = $start->modify('+'.(7 * 2468).' minutes');
    $to = $edge->modify('+30 minutes');

    $found = RuleScanner::between('FREQ=MINUTELY;INTERVAL=7', $start, $edge, $to, null, 1000);
    $plain = (new RRule('FREQ=MINUTELY;INTERVAL=7', $start))->getOccurrencesBetween($edge, $to);

    expect(array_map(fn ($d) => $d->getTimestamp(), $found->occurrences))->toBe(array_map(fn ($d) => $d->getTimestamp(), $plain))
        ->and($found->occurrences[0]->getTimestamp())->toBe($edge->getTimestamp())
        ->and($found->iterations)->toBeLessThan(20)
        ->and($found->truncated)->toBeFalse();
});

it('veinte series FREQ=MINUTELY desde 2020 caben en un render: tiempo y memoria acotados', function () {
    Log::spy();
    config(['kore-calendar.recurrence.max_total_occurrences' => 2500]);

    $series = [];
    foreach (range(1, 20) as $i) {
        $series[] = rlSeries("s{$i}", '2020-01-01 00:00:00', 'FREQ=MINUTELY;INTERVAL=5');
    }

    [$instances, $seconds, $memory] = rlMeasure(fn () => rlCollect($series, '2026-10-12', '2026-10-13'));

    // Cada serie tiene 288 en la vista (864 con los márgenes): sin tope global, 5 760. Con él, solo
    // las 2 500 ocurrencias de las primeras series, que son las de las tres primeras.
    expect(count($instances))->toBeLessThan(1000)->toBeGreaterThanOrEqual(2 * 288)
        ->and($memory)->toBeLessThan(40)
        ->and($seconds)->toBeLessThan(2.0);

    // Se avisa una sola vez por render, no una por serie omitida.
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'max_total_occurrences'))->once();
});

it('un COUNT enorme con una frecuencia alta no se puede saltar: tope duro de iteraciones y un aviso', function () {
    Log::spy();

    [$instances, $seconds, $memory] = rlMeasure(fn () => rlCollect([rlSeries('c', '2026-01-01 00:00:00', 'FREQ=SECONDLY;COUNT=99999999')], '2026-10-12', '2026-10-13'));

    // Llegar a octubre son 24 millones de segundos: el tope (50 000) corta mucho antes.
    expect($instances)->toBe([])
        ->and($memory)->toBeLessThan(40)
        ->and($seconds)->toBeLessThan(1.5);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'recurrence.max_iterations') && str_contains($m, '«c»'))->once();
});

it('el tope de iteraciones es del render entero: cien series con COUNT enorme no multiplican el coste', function () {
    Log::spy();
    config(['kore-calendar.recurrence.max_iterations' => 20000, 'kore-calendar.recurrence.max_total_iterations' => 60000]);

    $series = [];
    foreach (range(1, 100) as $i) {
        $series[] = rlSeries("c{$i}", '2026-01-01 00:00:00', 'FREQ=SECONDLY;COUNT=99999999');
    }

    [$instances, $seconds, $memory] = rlMeasure(fn () => rlCollect($series, '2026-10-12', '2026-10-13'));

    // Tres series agotan las 60 000 iteraciones; las otras 97 no recorren nada.
    expect($instances)->toBe([])->and($memory)->toBeLessThan(30)->and($seconds)->toBeLessThan(1.5);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'max_total_iterations'))->once();
});

it('el presupuesto se reinicia en cada rango: dos renders seguidos con el mismo colector no se restan', function () {
    config(['kore-calendar.recurrence.max_total_occurrences' => 1000]);

    $clock = new WallClock('UTC');
    $events = [rlSeries('a', '2026-01-01 00:00:00', 'FREQ=MINUTELY;INTERVAL=5'), rlSeries('b', '2026-01-01 00:00:00', 'FREQ=MINUTELY;INTERVAL=5')];
    $range = fn (string $from, string $to) => new DateRange(Marker::parse($from), Marker::parse($to));
    $collect = fn (EventCollector $c, DateRange $r) => count($c->collect([new ArraySource($events)], $r));

    $reused = new EventCollector($clock, new EventNormalizer($clock));
    $first = $collect($reused, $range('2026-10-12', '2026-10-13'));
    $second = $collect($reused, $range('2026-10-14', '2026-10-15'));

    // La primera serie gasta 864 de las 1 000 (con los márgenes) y la segunda se queda en 136, que ni llegan a la vista.
    // El segundo render parte de cero otra vez, no de lo que dejó el primero.
    expect($first)->toBe(288)->and($second)->toBe(288);
});

it('el presupuesto se puede pasar a expand(): lo gastado por una serie lo ven las siguientes', function () {
    $clock = new WallClock('UTC');
    $normalizer = new EventNormalizer($clock);
    $expander = new RecurrenceExpander($clock, $normalizer, 1000, 1500, 50000, 250000);
    $range = new DateRange(Marker::parse('2026-10-12'), Marker::parse('2026-10-13'));
    $budget = new OccurrenceBudget(1500, 250000);

    $a = $expander->expand(CalendarEvent::fromArray(rlSeries('a', '2026-01-01 00:00:00', 'FREQ=MINUTELY')), $range, [], $budget);
    $b = $expander->expand(CalendarEvent::fromArray(rlSeries('b', '2026-01-01 00:00:00', 'FREQ=MINUTELY')), $range, [], $budget);
    $c = $expander->expand(CalendarEvent::fromArray(rlSeries('c', '2026-01-01 00:00:00', 'FREQ=MINUTELY')), $range, [], $budget);

    expect([count($a), count($b), count($c)])->toBe([1000, 500, 0])->and($budget->occurrences())->toBe(0);
});

it('los topes por defecto están en el config', function () {
    expect(config('kore-calendar.recurrence'))->toMatchArray([
        'max_occurrences' => 1000,
        'max_total_occurrences' => 5000,
        'max_iterations' => 50000,
        'max_total_iterations' => 250000,
    ]);
});

it('una regla mal escrita sigue lanzando, también con el presupuesto agotado', function () {
    $clock = new WallClock('UTC');
    $expander = new RecurrenceExpander($clock, new EventNormalizer($clock), 1000, 0 + 1, 10, 10);
    $range = new DateRange(Marker::parse('2026-10-12'), Marker::parse('2026-10-13'));
    $budget = new OccurrenceBudget(0, 0);

    expect(fn () => $expander->expand(CalendarEvent::fromArray(rlSeries('x', '2026-10-01 09:00:00', 'FREQ=NUNCA')), $range, [], $budget))
        ->toThrow(InvalidArgumentException::class, 'rrule');
});

it('un UNTIL anterior a la vista no lanza al adelantar el inicio', function () {
    $i = rlCollect([rlSeries('u', '2020-01-01 09:00:00', 'FREQ=DAILY;UNTIL=20200601')], '2026-10-12', '2026-10-19');

    expect($i)->toBe([]);
});

// ── Paridad: adelantar el inicio no cambia el resultado ─────────────────

dataset('rl_zones', ['UTC', 'Europe/Madrid', 'America/Santiago', 'Australia/Lord_Howe']);

it('adelantar el inicio da exactamente las mismas ocurrencias que php-rrule a pelo, en zonas con cambio de hora', function (string $zone) {
    $tz = new DateTimeZone($zone);
    // Ventanas que cruzan el cambio de hora de primavera y otoño del norte y del sur, y sitios tranquilos.
    $windows = ['2026-03-29 00:00:00', '2026-10-25 00:00:00', '2026-04-05 00:00:00', '2026-09-06 00:00:00', '2026-03-08 06:00:00', '2026-10-24 22:30:00', '2026-03-29 03:00:10', '2026-10-25 02:30:00'];
    // Horas de pared del inicio, entre ellas las que caen en el hueco de la primavera (02:30).
    $times = ['09:00:00', '02:30:00', '00:00:00', '23:59:59', '01:30:00'];
    $checked = 0;
    $different = [];

    foreach (rlRules() as [$rule, $history, $span]) {
        foreach ($windows as $i => $window) {
            $from = new DateTimeImmutable($window, $tz);
            $dtstart = $from->modify('-'.$history.' hours');
            $dtstart = new DateTimeImmutable($dtstart->format('Y-m-d').' '.$times[($i + strlen($rule)) % count($times)], $tz);

            if ($dtstart > $from) {
                continue;
            }

            $to = $from->modify('+'.(int) ($span * 3600).' seconds');
            $expected = array_map(fn ($d) => $d->getTimestamp(), (new RRule($rule, $dtstart))->getOccurrencesBetween($from, $to));
            $scan = RuleScanner::between($rule, $dtstart, $from, $to, null, 1_000_000);

            if (array_map(fn ($d) => $d->getTimestamp(), $scan->occurrences) !== $expected) {
                $different[] = "{$rule} | inicio {$dtstart->format('c')} | vista {$from->format('c')}";
            }

            $checked++;
        }
    }

    expect($different)->toBe([])->and($checked)->toBeGreaterThan(150);
})->with('rl_zones');

/**
 * Las reglas, con cuántas horas de historia hay entre el inicio y la vista y cuántas dura la vista.
 * La historia se ajusta a la frecuencia para que la comparación con php-rrule a pelo (que sí
 * recorre todo) siga siendo barata. Las que no se adelantan (MONTHLY, YEARLY, COUNT, BYSETPOS)
 * tienen que dar lo mismo, claro.
 *
 * @return list<array{0: string, 1: int, 2: float|int}>
 */
function rlRules(): array
{
    return [
        ['FREQ=SECONDLY;INTERVAL=3', 1, 0.01], ['FREQ=SECONDLY;INTERVAL=7', 1, 0.01], ['FREQ=SECONDLY;BYSECOND=0,30', 1, 0.02], ['FREQ=SECONDLY;INTERVAL=45;BYMINUTE=0,5', 6, 0.5],
        ['FREQ=MINUTELY', 24, 3], ['FREQ=MINUTELY;INTERVAL=7', 100, 6], ['FREQ=MINUTELY;INTERVAL=30;BYHOUR=9,10,11', 200, 12], ['FREQ=MINUTELY;INTERVAL=90', 400, 30],
        ['FREQ=HOURLY', 24 * 40, 30], ['FREQ=HOURLY;INTERVAL=5', 24 * 60, 60], ['FREQ=HOURLY;INTERVAL=6;BYDAY=MO,TU', 24 * 100, 80], ['FREQ=HOURLY;BYMINUTE=0,30', 24 * 20, 10], ['FREQ=HOURLY;INTERVAL=7;UNTIL=20271231T000000Z', 24 * 50, 40],
        ['FREQ=DAILY', 24 * 420, 24 * 40], ['FREQ=DAILY;INTERVAL=3', 24 * 420, 24 * 40], ['FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR', 24 * 420, 24 * 30], ['FREQ=DAILY;BYMONTH=10,11', 24 * 420, 24 * 40], ['FREQ=DAILY;INTERVAL=2;UNTIL=20271231T000000Z', 24 * 300, 24 * 40],
        ['FREQ=WEEKLY', 24 * 380, 24 * 40], ['FREQ=WEEKLY;BYDAY=MO,WE,FR', 24 * 380, 24 * 40], ['FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH', 24 * 380, 24 * 60], ['FREQ=WEEKLY;WKST=SU;BYDAY=SU,SA', 24 * 380, 24 * 40], ['FREQ=WEEKLY;INTERVAL=3;WKST=MO;BYDAY=SU,MO', 24 * 380, 24 * 60],
        ['FREQ=MONTHLY;BYMONTHDAY=15', 24 * 380, 24 * 90], ['FREQ=YEARLY', 24 * 2200, 24 * 400], ['FREQ=DAILY;COUNT=500', 24 * 300, 24 * 40], ['FREQ=WEEKLY;BYDAY=MO;BYSETPOS=1', 24 * 350, 24 * 40], ['FREQ=HOURLY;INTERVAL=4;BYMINUTE=0,30;BYSETPOS=1', 24 * 30, 40],
    ];
}

it('la expansión de una serie de años atrás da las mismas instancias que una de hace unos días, para las mismas fechas', function () {
    // Una regla diaria flotante, y una con zona, vistas en Madrid: el recorrido adelantado no mueve la hora tras el cambio.
    $madrid = new DateTimeZone('Europe/Madrid');
    $events = [
        ['id' => 'f', 'title' => 'F', 'start' => '2016-10-16 09:00:00', 'end' => '2016-10-16 10:00:00', 'rrule' => 'FREQ=DAILY'],
        ['id' => 'z', 'title' => 'Z', 'start' => CarbonImmutable::parse('2016-10-16 09:00', $madrid), 'end' => CarbonImmutable::parse('2016-10-16 10:00', $madrid), 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,SU'],
        ['id' => 'h', 'title' => 'H', 'start' => '2026-10-01 00:00:00', 'end' => '2026-10-01 00:20:00', 'rrule' => 'FREQ=HOURLY;INTERVAL=5'],
    ];

    $instances = rlCollect($events, '2026-10-20', '2026-11-03', 'Europe/Madrid');
    $byId = fn (string $id) => array_values(array_map(fn ($i) => gmdate('Y-m-d H:i', $i->range->start->ts), array_filter($instances, fn ($i) => $i->seriesId === $id)));
    usort($instances, fn ($a, $b) => $a->range->start->ts <=> $b->range->start->ts);

    // Diaria a las 9 todos los días, también el 25-oct (cambio de hora): 14 días.
    expect($byId('f'))->toHaveCount(14)->and($byId('f')[0])->toBe('2026-10-20 09:00')->and($byId('f')[5])->toBe('2026-10-25 09:00')
        ->and($byId('z'))->toBe(['2026-10-25 09:00', '2026-10-26 09:00', '2026-11-01 09:00', '2026-11-02 09:00'])
        // Cada 5 horas desde el 1-oct a las 00:00 de pared (la hora 460 es el 20-oct a las 04:00).
        ->and($byId('h')[0])->toBe('2026-10-20 04:00');
});

// ── RuleScanner ─────────────────────────────────────────────────────────

it('RuleScanner respeta el límite de resultados y avisa si se cortó por iteraciones', function () {
    $start = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    $to = new DateTimeImmutable('2026-12-31 00:00:00', new DateTimeZone('UTC'));

    $limited = RuleScanner::between('FREQ=DAILY;COUNT=400', $start, null, $to, 10, 100);
    $cut = RuleScanner::between('FREQ=DAILY;COUNT=400', $start, new DateTimeImmutable('2026-12-01 00:00:00', new DateTimeZone('UTC')), $to, null, 100);

    expect($limited->occurrences)->toHaveCount(10)->and($limited->truncated)->toBeFalse()
        ->and($cut->occurrences)->toBe([])->and($cut->truncated)->toBeTrue()->and($cut->iterations)->toBe(100);
});

it('RuleScanner valida la regla aunque no quepa ninguna ocurrencia', function () {
    $start = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

    expect(fn () => RuleScanner::between('FREQ=NUNCA', $start, null, null, 0, 10))->toThrow(InvalidArgumentException::class)
        ->and(RuleScanner::between('FREQ=DAILY', $start, null, null, 0, 10)->occurrences)->toBe([]);
});

it('RuleScanner::frequency lee la FREQ como php-rrule: la última manda, sin distinguir mayúsculas', function () {
    expect(RuleScanner::frequency('FREQ=YEARLY;FREQ=SECONDLY'))->toBe('SECONDLY')
        ->and(RuleScanner::frequency('freq=daily;count=3'))->toBe('DAILY')
        ->and(RuleScanner::frequency('RRULE:FREQ=WEEKLY'))->toBe('WEEKLY')
        ->and(RuleScanner::frequency('COUNT=3'))->toBeNull();
});

// ── RecurrenceEditor: «esta y las siguientes» con un COUNT enorme ───────

it('splitAt con COUNT=99999999 y FREQ=SECONDLY no genera cien millones de ocurrencias', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00:00', 'end' => '2026-10-12 09:00:01', 'rrule' => 'FREQ=SECONDLY;COUNT=99999999'];
    $series = CalendarEvent::fromArray($row);
    $instances = rlCollect([$row], '2026-10-12', '2026-10-13');

    [$cut, $seconds, $memory] = rlMeasure(fn () => RecurrenceEditor::splitAt($series, $instances[500]));

    // La ocurrencia 501 (índice 500): antes hay 500; quedan 99 999 999 − 500, ella incluida.
    expect($cut['after'])->toBe('FREQ=SECONDLY;COUNT=99999499')
        ->and($cut['before'])->toStartWith('FREQ=SECONDLY;UNTIL=')
        ->and($seconds)->toBeLessThan(1.0)
        ->and($memory)->toBeLessThan(24);
});

it('splitAt con un COUNT largo y una ocurrencia que queda más allá del tope dice que no se puede cortar, sin agotar la memoria', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-01-01 00:00:00', 'end' => '2026-01-01 00:00:01', 'rrule' => 'FREQ=SECONDLY;COUNT=99999999'];
    $series = CalendarEvent::fromArray($row);

    // 24 millones de ocurrencias antes de esta: más que el tope de iteraciones.
    $occurrence = ['seriesId' => 's', 'recurrenceId' => Marker::parse('2026-10-12 09:00:00'), 'occurrenceStart' => CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC')];

    [$result, $seconds, $memory] = rlMeasure(function () use ($series, $occurrence) {
        try {
            RecurrenceEditor::splitAt($series, $occurrence);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    });

    expect($result)->toContain('demasiadas ocurrencias')->toContain('max_iterations')
        ->and($memory)->toBeLessThan(40)
        ->and($seconds)->toBeLessThan(1.5);
});

it('splitAt sigue rechazando una ocurrencia que no genera la regla', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00:00', 'end' => '2026-10-12 10:00:00', 'rrule' => 'FREQ=DAILY;COUNT=3'];
    $series = CalendarEvent::fromArray($row);
    $late = ['seriesId' => 's', 'recurrenceId' => Marker::parse('2026-12-01 09:00:00'), 'occurrenceStart' => CarbonImmutable::parse('2026-12-01 09:00:00', 'UTC')];

    expect(fn () => RecurrenceEditor::splitAt($series, $late))->toThrow(InvalidArgumentException::class, 'no es una de las que genera su regla');
});
