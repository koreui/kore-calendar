<?php

namespace KoreUi\Calendar\ICal;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use KoreUi\Calendar\Recurrence\RecurrenceExpander;
use RRule\RRule;

/**
 * Un VTIMEZONE leído: de qué desplazamiento a cuál pasa una zona y cuándo
 * (RFC 5545 §3.6.5). Sirve para dos cosas: dar el desplazamiento de un TZID que
 * PHP no conoce (los nombres de Windows que exporta Outlook) y, en las pruebas,
 * comprobar que el VTIMEZONE que escribimos dice lo mismo que la base de datos de zonas.
 *
 * Los componentes cambian de dos maneras: con una regla anual (`RRULE:FREQ=YEARLY;
 * BYMONTH=10;BYDAY=-1SU`, lo que usan Outlook y Google) o con fechas explícitas
 * (`RDATE`, y lo que genera `TimezoneWriter`). Las dos se calculan aquí.
 *
 * @internal
 */
final class TimezoneDefinition
{
    /** @var array<int, list<array{ts: int, from: int, to: int}>> Cambios de [año-1, año+1], por año. */
    private array $years = [];

    /**
     * `start` y `rdates` son horas de pared en el desplazamiento anterior (`from`),
     * como segundos desde 1970 tomados como UTC (la convención de `Marker`).
     *
     * @param  list<array{type: string, from: int, to: int, start: int, rrule: ?string, rdates: list<int>}>  $components
     */
    private function __construct(
        public readonly string $tzid,
        private readonly array $components,
        public readonly ?string $location,
    ) {}

    /**
     * @throws ICalException
     */
    public static function fromComponent(Component $vtimezone): self
    {
        $tzid = trim(Text::unescape($vtimezone->first('TZID')->value ?? ''));

        if ($tzid === '') {
            throw new ICalException("Línea {$vtimezone->line}: el VTIMEZONE no tiene TZID.", $vtimezone->line);
        }

        $components = [];

        foreach ($vtimezone->children as $child) {
            if ($child->name !== 'STANDARD' && $child->name !== 'DAYLIGHT') {
                continue;
            }

            $start = $child->first('DTSTART');
            $from = $child->first('TZOFFSETFROM');
            $to = $child->first('TZOFFSETTO');

            if ($start === null || $from === null || $to === null) {
                throw new ICalException("Línea {$child->line}: el {$child->name} del VTIMEZONE «{$tzid}» necesita DTSTART, TZOFFSETFROM y TZOFFSETTO.", $child->line);
            }

            $rdates = [];
            foreach ($child->all('RDATE') as $rdate) {
                foreach (explode(',', $rdate->value) as $piece) {
                    $rdates[] = self::wall($rdate, trim($piece));
                }
            }

            $components[] = [
                'type' => $child->name,
                'from' => self::offset($from),
                'to' => self::offset($to),
                'start' => self::wall($start, trim($start->value)),
                'rrule' => ($rule = $child->first('RRULE')) ? trim($rule->value) : null,
                'rdates' => $rdates,
            ];
        }

        if ($components === []) {
            throw new ICalException("Línea {$vtimezone->line}: el VTIMEZONE «{$tzid}» no define ningún STANDARD ni DAYLIGHT.", $vtimezone->line);
        }

        $location = $vtimezone->first('X-LIC-LOCATION');

        return new self($tzid, $components, $location ? trim($location->value) : null);
    }

    /**
     * Los cambios cuyo instante cae en [from, to], ordenados. `from` a null es "desde
     * siempre": hace falta para saber en qué estado estaba una zona cuya última regla
     * acabó hace décadas.
     *
     * @return list<array{ts: int, from: int, to: int}>
     */
    public function onsetsBetween(?int $from, int $to): array
    {
        $onsets = [];

        foreach ($this->components as $component) {
            // El instante es la pared menos el desplazamiento de antes del cambio.
            $wallFrom = $from === null ? null : $from + $component['from'];
            $wallTo = $to + $component['from'];

            foreach ($this->walls($component, $wallFrom, $wallTo) as $wall) {
                $onsets[$wall - $component['from']] = ['ts' => $wall - $component['from'], 'from' => $component['from'], 'to' => $component['to']];
            }
        }

        ksort($onsets);

        return array_values($onsets);
    }

    /**
     * El desplazamiento en segundos que tiene la zona en un instante.
     *
     * Los cambios se calculan por años (el pedido, el anterior y el siguiente) y se guardan:
     * una regla de Outlook empieza en 1601, y recorrerla entera por cada consulta haría
     * lento resolver un archivo con cientos de eventos.
     */
    public function offsetAt(int $ts): int
    {
        $year = (int) gmdate('Y', $ts);
        $this->years[$year] ??= $this->onsetsBetween(gmmktime(0, 0, 0, 1, 1, $year - 1), gmmktime(23, 59, 59, 12, 31, $year + 1));

        $known = array_filter($this->years[$year], fn (array $o) => $o['ts'] <= $ts);

        // Sin ningún cambio en esos tres años (una regla que acabó antes): se busca desde siempre.
        $onsets = $known ?: $this->onsetsBetween(null, $ts);

        if ($onsets !== []) {
            return $onsets[array_key_last($onsets)]['to'];
        }

        // Antes del primer cambio conocido: el desplazamiento del que partía el más antiguo.
        $earliest = $this->components[0];
        foreach ($this->components as $component) {
            if ($component['start'] - $component['from'] < $earliest['start'] - $earliest['from']) {
                $earliest = $component;
            }
        }

        return $earliest['from'];
    }

    /**
     * @param  array{type: string, from: int, to: int, start: int, rrule: ?string, rdates: list<int>}  $component
     * @return list<int> Horas de pared de los cambios dentro de la ventana.
     */
    private function walls(array $component, ?int $from, int $to): array
    {
        $walls = [];

        foreach ([$component['start'], ...$component['rdates']] as $wall) {
            if (($from === null || $wall >= $from) && $wall <= $to) {
                $walls[] = $wall;
            }
        }

        if ($component['rrule'] !== null) {
            $utc = new DateTimeZone('UTC');

            try {
                $rule = new RRule(
                    RecurrenceExpander::normalizeUntil($component['rrule'], null),
                    new DateTimeImmutable('@'.$component['start']),
                );

                foreach ($rule->getOccurrencesBetween(
                    $from === null ? null : (new DateTimeImmutable('@'.$from))->setTimezone($utc),
                    (new DateTimeImmutable('@'.$to))->setTimezone($utc),
                ) as $occurrence) {
                    $walls[] = (int) $occurrence->format('U');
                }
            } catch (InvalidArgumentException) {
                // Una regla que no entendemos no tumba el resto del VTIMEZONE: quedan sus fechas explícitas.
            }
        }

        return $walls;
    }

    /** `+0200` / `-050000` → segundos. */
    private static function offset(ContentLine $line): int
    {
        if (! preg_match('/^([+-])(\d{2})(\d{2})(\d{2})?$/', trim($line->value), $m)) {
            throw new ICalException("Línea {$line->line}: {$line->name} «{$line->value}» no es un desplazamiento válido (se espera +0200 o -0500).", $line->line);
        }

        $seconds = (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) ($m[4] ?? 0);

        return $m[1] === '-' ? -$seconds : $seconds;
    }

    private static function wall(ContentLine $line, string $value): int
    {
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})?$/', $value, $m)) {
            throw new ICalException("Línea {$line->line}: {$line->name} «{$value}» no es una fecha y hora válida (se espera 20261025T030000).", $line->line);
        }

        return gmmktime((int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), (int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
