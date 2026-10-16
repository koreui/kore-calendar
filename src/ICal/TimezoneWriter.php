<?php

namespace KoreUi\Calendar\ICal;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Un VTIMEZONE mínimo y válido (RFC 5545 §3.6.5) a partir de la base de datos de
 * zonas de PHP.
 *
 * **Fechas explícitas, no reglas.** Un STANDARD/DAYLIGHT por cada cambio de hora real
 * del rango de años, cada uno con su DTSTART. Derivar un `RRULE:FREQ=YEARLY;BYMONTH=10;
 * BYDAY=-1SU` desde las transiciones de PHP funciona para Europa y Estados Unidos y
 * falla en las zonas con reglas irregulares (Marruecos con el Ramadán, Irán, Israel);
 * las fechas valen para todas. El precio es que el VTIMEZONE cubre solo los años que
 * usan los eventos: fuera de ellos el cliente usa su propia base de datos, que es lo que
 * hacen Google y Apple de todos modos cuando el TZID es un nombre IANA.
 *
 * Sin cambios en el rango (Asia/Dubai, o un desplazamiento fijo), un único componente
 * con el mismo desplazamiento de origen y destino: RFC 5545 exige al menos uno.
 *
 * @internal
 */
final class TimezoneWriter
{
    /**
     * @return list<string> Líneas sin plegar.
     */
    public static function build(string $tzid, DateTimeZone $zone, int $fromYear, int $toYear): array
    {
        $lines = ['BEGIN:VTIMEZONE', 'TZID:'.Text::clean($tzid)];

        // Solo las zonas con nombre IANA se pueden localizar en un cliente por X-LIC-LOCATION.
        if ($zone->getLocation() !== false) {
            $lines[] = 'X-LIC-LOCATION:'.Text::clean($zone->getName());
        }

        foreach (self::components($zone, $fromYear, $toYear) as $c) {
            array_push(
                $lines,
                'BEGIN:'.$c['type'],
                'TZNAME:'.Text::clean($c['name']),
                'TZOFFSETFROM:'.self::offset($c['from']),
                'TZOFFSETTO:'.self::offset($c['to']),
                'DTSTART:'.$c['start'],
                'END:'.$c['type'],
            );
        }

        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    /**
     * @return list<array{type: string, name: string, from: int, to: int, start: string}>
     */
    public static function components(DateTimeZone $zone, int $fromYear, int $toYear): array
    {
        $fromYear = max(1970, $fromYear);
        $toYear = max($fromYear, $toYear);

        // Una zona con desplazamiento (`+02:00`) no tiene transiciones: getTransitions() devuelve false.
        $transitions = $zone->getTransitions(gmmktime(0, 0, 0, 1, 1, $fromYear), gmmktime(0, 0, 0, 1, 1, $toYear + 1)) ?: [];

        if ($transitions === []) {
            $offset = $zone->getOffset(new DateTimeImmutable('@'.gmmktime(0, 0, 0, 1, 1, $fromYear)));

            return [['type' => 'STANDARD', 'name' => self::fixedName($zone, $offset), 'from' => $offset, 'to' => $offset, 'start' => '19700101T000000']];
        }

        // El primer elemento es el estado al empezar el rango, no un cambio.
        $state = array_shift($transitions);
        $previous = $state['offset'];
        $components = [];

        foreach ($transitions as $t) {
            // Un cambio de abreviatura sin cambio de desplazamiento no mueve ningún reloj.
            if ($t['offset'] === $previous) {
                continue;
            }

            $components[] = [
                'type' => $t['isdst'] ? 'DAYLIGHT' : 'STANDARD',
                'name' => $t['abbr'],
                // La hora de pared del cambio se escribe en el desplazamiento de ANTES.
                'from' => $previous,
                'to' => $t['offset'],
                'start' => gmdate('Ymd\THis', $t['ts'] + $previous),
            ];

            $previous = $t['offset'];
        }

        if ($components === []) {
            return [[
                'type' => $state['isdst'] ? 'DAYLIGHT' : 'STANDARD',
                'name' => $state['abbr'],
                'from' => $state['offset'],
                'to' => $state['offset'],
                'start' => '19700101T000000',
            ]];
        }

        return $components;
    }

    /** `+0200` (o `+053045` si trae segundos). */
    public static function offset(int $seconds): string
    {
        $abs = abs($seconds);
        $text = sprintf('%s%02d%02d', $seconds < 0 ? '-' : '+', intdiv($abs, 3600), intdiv($abs % 3600, 60));

        return $abs % 60 === 0 ? $text : $text.sprintf('%02d', $abs % 60);
    }

    /** El nombre de un desplazamiento fijo: `UTC+0200`, o la abreviatura de la zona si la tiene. */
    private static function fixedName(DateTimeZone $zone, int $offset): string
    {
        $name = $zone->getName();

        return preg_match('/^[+-]\d{2}:\d{2}$/D', $name) ? 'UTC'.self::offset($offset) : $name;
    }
}
