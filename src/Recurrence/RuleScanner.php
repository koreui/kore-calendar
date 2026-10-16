<?php

namespace KoreUi\Calendar\Recurrence;

use DateTimeImmutable;
use DateTimeInterface;
use RRule\RRule;
use Throwable;

/**
 * Recorre una regla con php-rrule sin que su coste dependa de lo hostil que sea.
 *
 * php-rrule genera las ocurrencias desde `DTSTART` y las guarda todas en una caché
 * (unos 350 bytes cada una), también las anteriores al rango que se pide:
 * `getOccurrencesBetween()` no se salta nada. `DTSTART:20200101` con `FREQ=MINUTELY`
 * son tres millones de ocurrencias antes de llegar a esta semana, y 256 MB de memoria.
 * Dos defensas:
 *
 * - **Adelantar el inicio.** Sin `COUNT`, las ocurrencias de una regla de segundos, minutos,
 *   horas, días o semanas son `DTSTART + k · INTERVAL` filtradas por los `BYxxx`: empezar
 *   en el múltiplo del intervalo anterior al rango da las mismas desde ahí. Se hace en hora
 *   de pared (php-rrule suma campos de pared, no segundos reales) y se comprueba que el
 *   nuevo inicio existe en su zona (no cae en el hueco del cambio de hora). Con `COUNT` no
 *   se puede: habría que contar las que se saltan. Con `BYSETPOS` tampoco, porque elige
 *   dentro de cada marco y el primero quedaría cortado. `MONTHLY` y `YEARLY` no se adelantan:
 *   recorrerlas desde el año 1 son 25 000 y 2 000 iteraciones.
 * - **Un tope duro de iteraciones.** Lo que no se puede adelantar (un `COUNT` enorme con
 *   `FREQ=SECONDLY`, un `BYSETPOS`) se corta tras `$maxIterations` ocurrencias recorridas,
 *   y el resultado dice que se cortó.
 *
 * @internal
 */
final class RuleScanner
{
    /** Ocurrencias que se recorren por serie si el config no dice otra cosa. */
    public const MAX_ITERATIONS = 50_000;

    /** Segundos que dura un paso de INTERVAL = 1 en las frecuencias que se pueden adelantar. */
    private const STEP = ['SECONDLY' => 1, 'MINUTELY' => 60, 'HOURLY' => 3600, 'DAILY' => 86_400, 'WEEKLY' => 604_800];

    /**
     * Las ocurrencias de `$rule` (con su inicio `$dtstart`) entre `$from` y `$to`, ambos incluidos.
     *
     * @param  ?int  $limit  Cuántas devolver como máximo (null = todas las del rango). 0 o menos: ninguna; la regla se valida igual.
     * @param  int  $maxIterations  Tope de ocurrencias que se recorren desde el inicio de la regla (o desde el inicio adelantado).
     *
     * @throws \InvalidArgumentException Si php-rrule no entiende la regla.
     */
    public static function between(string $rule, DateTimeImmutable $dtstart, ?DateTimeInterface $from, ?DateTimeInterface $to, ?int $limit, int $maxIterations): RuleScan
    {
        $start = $from === null ? $dtstart : self::forward($rule, $dtstart, $from);
        $iterator = new RRule($rule, $start);

        if ($limit !== null && $limit < 1) {
            return new RuleScan([], 0, false);
        }

        $found = [];
        $seen = 0;

        foreach ($iterator as $occurrence) {
            if ($to !== null && $occurrence > $to) {
                break;
            }

            if ($seen >= $maxIterations) {
                return new RuleScan($found, $seen, true);
            }

            $seen++;

            if ($from !== null && $occurrence < $from) {
                continue;
            }

            $found[] = $occurrence;

            if ($limit !== null && count($found) >= $limit) {
                break;
            }
        }

        return new RuleScan($found, $seen, false);
    }

    /**
     * Cuántas ocurrencias hay ANTES de `$instant` y si queda alguna en `$instant` o después.
     * Para repartir un `COUNT` al cortar una serie: no hace falta generar las que siguen.
     *
     * @return array{before: int, found: bool, truncated: bool}
     *
     * @throws \InvalidArgumentException Si php-rrule no entiende la regla.
     */
    public static function position(string $rule, DateTimeImmutable $dtstart, DateTimeInterface $instant, int $maxIterations): array
    {
        $before = 0;

        foreach (new RRule($rule, $dtstart) as $occurrence) {
            if ($occurrence >= $instant) {
                return ['before' => $before, 'found' => true, 'truncated' => false];
            }

            if ($before >= $maxIterations) {
                return ['before' => $before, 'found' => false, 'truncated' => true];
            }

            $before++;
        }

        return ['before' => $before, 'found' => false, 'truncated' => false];
    }

    /**
     * La `FREQ` de la regla en mayúsculas (`null` si no la tiene), leída como la lee php-rrule:
     * si se repite, manda la última.
     */
    public static function frequency(string $rule): ?string
    {
        return self::parts($rule)['FREQ'] ?? null;
    }

    /** Un tope del config (`kore-calendar.recurrence.{clave}`), o `$default` fuera de Laravel o si no es válido. */
    public static function limit(string $key, int $default): int
    {
        try {
            $value = function_exists('config') ? config('kore-calendar.recurrence.'.$key) : null;
        } catch (Throwable) {
            $value = null;
        }

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : $default;
    }

    /**
     * El inicio adelantado al múltiplo del intervalo anterior a `$from`, o el mismo `$dtstart`
     * si no se puede (ver la nota de la clase) o no hace falta.
     */
    private static function forward(string $rule, DateTimeImmutable $dtstart, DateTimeInterface $from): DateTimeImmutable
    {
        $parts = self::parts($rule);
        $unit = self::STEP[$parts['FREQ'] ?? ''] ?? null;

        if ($unit === null || isset($parts['COUNT']) || isset($parts['BYSETPOS'])) {
            return $dtstart;
        }

        $interval = $parts['INTERVAL'] ?? '1';

        if (! ctype_digit($interval) || (int) $interval < 1 || strlen($interval) > 9) {
            return $dtstart;
        }

        $step = $unit * (int) $interval;
        $zone = $dtstart->getTimezone();
        $wallStart = self::wall($dtstart);
        $wallFrom = self::wall(DateTimeImmutable::createFromInterface($from)->setTimezone($zone));

        // Un paso de margen: lo que queda antes de `$from` da igual, pero la ocurrencia que
        // coincide con él tiene que seguir en la serie.
        $k = intdiv($wallFrom - $wallStart, $step) - 1;

        // Si ese instante de pared no existe en la zona (el hueco de la primavera), PHP lo
        // corre una hora y la secuencia cambia de fase. Se retrocede hasta salir del hueco.
        foreach ([0, 7_200, 172_800] as $back) {
            $n = $k - intdiv($back + $step - 1, $step);

            if ($n < 1) {
                break;
            }

            $text = gmdate('Y-m-d H:i:s', $wallStart + $n * $step);
            $candidate = new DateTimeImmutable($text, $zone);

            if ($candidate->format('Y-m-d H:i:s') === $text) {
                return $candidate;
            }
        }

        return $dtstart;
    }

    /**
     * Las partes de la regla, con el nombre y el valor en mayúsculas.
     *
     * @return array<string, string>
     */
    private static function parts(string $rule): array
    {
        $parts = [];

        foreach (explode(';', strtoupper(preg_replace('/^\s*RRULE:/i', '', trim($rule)))) as $piece) {
            [$name, $value] = array_pad(explode('=', $piece, 2), 2, '');
            $parts[trim($name)] = trim($value);
        }

        return $parts;
    }

    /** Los campos de pared de una fecha como segundos desde 1970 tomados como UTC (la convención de `Marker`). */
    private static function wall(DateTimeInterface $date): int
    {
        return gmmktime((int) $date->format('G'), (int) $date->format('i'), (int) $date->format('s'), (int) $date->format('n'), (int) $date->format('j'), (int) $date->format('Y'));
    }
}
