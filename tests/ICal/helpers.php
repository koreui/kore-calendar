<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\Sources\ArraySource;
use KoreUi\Calendar\ICal\ICalWriter;
use KoreUi\Calendar\ICal\Text;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

/*
| Ayudas de los tests de iCalendar. Prefijo `ical` para no chocar con las globales
| de otros archivos (`ix`, `rc`, `ly`…).
*/

/**
 * Ejecuta `$fn` y devuelve [resultado, segundos, MB de pico de memoria sobre el punto de partida]:
 * los tests de archivos hostiles comprueban que el coste no depende de lo hostil que sea el archivo.
 *
 * @return array{0: mixed, 1: float, 2: float}
 */
function icalMeasure(callable $fn): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $start = hrtime(true);

    $result = $fn();

    return [$result, (hrtime(true) - $start) / 1e9, (memory_get_peak_usage() - $base) / 1048576];
}

/** Escribe con el DTSTAMP y el dominio fijos: la salida es la misma siempre. */
function icalWrite(iterable $events, array $options = []): string
{
    return (new ICalWriter)->write($events, [...['dtstamp' => '2026-10-16T08:00:00Z', 'uidDomain' => 'ej.test'], ...$options]);
}

/** Las líneas lógicas (ya desplegadas) del archivo. */
function icalLines(string $ics): array
{
    return array_values(array_filter(array_map(fn (array $l) => $l[1], Text::unfold($ics)), fn (string $l) => $l !== ''));
}

/**
 * Las líneas de cada componente `$name`, con su BEGIN y su END.
 *
 * @return list<list<string>>
 */
function icalBlocks(string $ics, string $name): array
{
    $blocks = [];
    $current = null;

    foreach (icalLines($ics) as $line) {
        if ($line === "BEGIN:{$name}") {
            $current = [$line];

            continue;
        }

        if ($current !== null) {
            $current[] = $line;

            if ($line === "END:{$name}") {
                $blocks[] = $current;
                $current = null;
            }
        }
    }

    return $blocks;
}

/** Un VEVENT del archivo por su UID (y, si hay varios, el que tiene RECURRENCE-ID con `$recurrence`). */
function icalVevent(string $ics, string $uid, bool $override = false): array
{
    foreach (icalBlocks($ics, 'VEVENT') as $block) {
        if (in_array("UID:{$uid}", $block, true) && $override === (bool) array_filter($block, fn ($l) => str_starts_with($l, 'RECURRENCE-ID'))) {
            return $block;
        }
    }

    throw new RuntimeException("No hay un VEVENT con UID {$uid}.");
}

function icalFixture(string $name): string
{
    return file_get_contents(__DIR__.'/fixtures/'.$name);
}

/** Un archivo iCalendar mínimo con los VEVENT dados, con CRLF como manda la RFC. */
function icalDoc(string ...$components): string
{
    return str_replace("\n", "\r\n", "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//test//ES\n".implode("\n", $components)."\nEND:VCALENDAR\n");
}

/** Un VEVENT a partir de sus líneas. */
function icalEvent(string ...$lines): string
{
    return "BEGIN:VEVENT\n".implode("\n", $lines)."\nEND:VEVENT";
}

/**
 * La forma observable de un evento, para compararlo con otro aunque uno venga de un `.ics`:
 * las fechas por su representación (`d:` día, `w:` hora de pared, `i:` instante) y el resto como strings.
 *
 * @return array<string, mixed>
 */
function icalShape(CalendarEvent $e): array
{
    $date = function (DateTimeInterface|string|null $v): ?string {
        if ($v === null) {
            return null;
        }

        if ($v instanceof DateTimeInterface) {
            return 'i:'.$v->getTimestamp().'@'.$v->getTimezone()->getName();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return 'd:'.$v;
        }

        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $v)) {
            return 'i:'.(new DateTimeImmutable($v))->getTimestamp();
        }

        return 'w:'.gmdate('Y-m-d H:i:s', strtotime($v.' UTC'));
    };

    $clock = new WallClock('UTC');
    $exdates = array_map($date, $e->exdates);
    sort($exdates);

    return [
        // La excepción no tiene UID propio en iCalendar: su id vuelve derivado de la serie y de su RECURRENCE-ID.
        'id' => $e->isOverride() ? '(excepción)' : (string) $e->id,
        'title' => $e->title,
        'start' => $date($e->start),
        'end' => $date($e->end),
        // `allDay` null y `allDay` true con fechas sin hora son el mismo evento.
        'allDay' => (new EventNormalizer($clock))->normalize($e)->allDay,
        'resourceIds' => array_map('strval', $e->resourceIds),
        'color' => $e->color,
        'url' => $e->url,
        'rrule' => $e->rrule,
        'exdates' => $exdates,
        'recurrenceOf' => $e->recurrenceOf === null ? null : (string) $e->recurrenceOf,
        'recurrenceId' => $date($e->recurrenceId),
    ];
}

/**
 * Lo que el colector saca de unos eventos en un rango (sin ordenar), con la vista en `$zone`.
 *
 * @param  list<CalendarEvent>  $events
 * @return list<KoreUi\Calendar\Events\EventInstance>
 */
function icalInstances(array $events, string $from, string $to, string $zone = 'Europe/Madrid'): array
{
    $clock = new WallClock($zone);

    return (new EventCollector($clock, new EventNormalizer($clock)))
        ->collect([new ArraySource($events)], new DateRange(Marker::parse($from), Marker::parse($to)));
}

/**
 * Las ocurrencias que el colector saca de unos eventos en un rango, ordenadas: inicio y fin de
 * pared de la vista, tipo, serie, id de instancia y título.
 *
 * @param  list<CalendarEvent>  $events
 * @return list<array<int, string>>
 */
function icalOccurrences(array $events, string $from, string $to, string $zone = 'Europe/Madrid'): array
{
    $rows = array_map(fn ($i) => [
        $i->range->start->toDateTimeString(),
        $i->range->end->toDateTimeString(),
        ($i->allDay ? 'allDay' : 'timed').($i->hasEnd ? '+end' : ''),
        (string) ($i->seriesId ?? '-'),
        $i->instanceId,
        $i->event->title,
    ], icalInstances($events, $from, $to, $zone));

    sort($rows);

    return $rows;
}

/**
 * Las líneas de un VTIMEZONE (con su BEGIN y su END) como definición consultable: lo que el
 * lector hace con el de un archivo, para comprobar que lo que ESCRIBIMOS dice lo mismo que la
 * base de datos de zonas de PHP.
 *
 * @param  list<string>  $lines
 */
function icalDefinition(array $lines): KoreUi\Calendar\ICal\TimezoneDefinition
{
    $stack = [];
    $root = null;

    foreach ($lines as $n => $text) {
        $line = KoreUi\Calendar\ICal\ContentLine::parse($text, $n + 1);

        if ($line->name === 'BEGIN') {
            $component = new KoreUi\Calendar\ICal\Component($line->value, $n + 1);
            $stack === [] ? $root = $component : $stack[array_key_last($stack)]->children[] = $component;
            $stack[] = $component;
        } elseif ($line->name === 'END') {
            array_pop($stack);
        } else {
            $stack[array_key_last($stack)]->lines[] = $line;
        }
    }

    return KoreUi\Calendar\ICal\TimezoneDefinition::fromComponent($root);
}
