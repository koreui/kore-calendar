<?php

namespace KoreUi\Calendar\Interaction;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;

/**
 * Los límites de fecha que el servidor acepta de un navegador. Son dos:
 *
 * - **Los duros** (`MIN`, `MAX`, `contains()`): un marker llega como un entero
 *   cualquiera. Sin tope, `PHP_INT_MAX` llegaría a `WallClock::toInstant()` y de ahí a
 *   la base de datos del consumidor, donde un DATETIME de MySQL no pasa del año 9999.
 *   Los años 1-9999 son los que PHP formatea con cuatro cifras: dentro de ese rango
 *   todo lo que hace el motor (sumar, redondear, formatear) es seguro.
 *
 * - **La ventana** (`window()`, `acceptsDate()`): lo que se acepta cuando el
 *   consumidor no dice otra cosa, `WINDOW_YEARS` años a cada lado de hoy. Aceptar
 *   hasta el año 9999 no sirve a nadie y deja a cualquiera pedir una vista de ocho mil
 *   años o crear un evento en el 3000. `valid_range` la sustituye (ampliándola o
 *   reduciéndola) y siempre queda dentro de los límites duros.
 */
final class Bounds
{
    /** 0001-01-01 00:00. */
    public const MIN = -62_135_596_800;

    /** 10000-01-01 00:00 (exclusivo): el primer segundo que ya no cabe. */
    public const MAX = 253_402_300_800;

    /** Años a cada lado de hoy que se aceptan sin `valid_range`. */
    public const WINDOW_YEARS = 200;

    /** Dentro de los límites duros (años 1-9999). La ventana por defecto es más estrecha: `window()`. */
    public static function contains(int ...$markers): bool
    {
        foreach ($markers as $ts) {
            if ($ts < self::MIN || $ts >= self::MAX) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lo que se acepta: `[inicio, fin)` en markers.
     *
     * Sin `$valid`, `WINDOW_YEARS` años a cada lado de hoy. Con él, el rango del
     * consumidor (sustituye a la ventana, no se cruza con ella). Un extremo abierto
     * (el que `valid_range` deja en los límites duros cuando solo se escribió el otro)
     * cae a la ventana, salvo que quede al otro lado del extremo escrito (un inicio
     * en el año 2300 sin fin): ahí queda abierto hasta el límite duro, que es lo que
     * pide quien escribe esa fecha. (Escribir exactamente `0001-01-01` o `9999-12-31`
     * es lo mismo que dejarlo abierto: son los propios límites duros.)
     */
    public static function window(?DateRange $valid = null): DateRange
    {
        $year = (int) CarbonImmutable::now('UTC')->format('Y');
        $low = Marker::of(max(1, $year - self::WINDOW_YEARS), 1, 1)->ts;
        $high = Marker::of($year + self::WINDOW_YEARS + 1, 1, 1)->ts;

        if ($valid !== null) {
            $start = $valid->start->ts;
            $end = $valid->end->ts;
            $openStart = $start <= self::MIN;
            $openEnd = $end >= self::MAX;

            $low = $openStart ? $low : $start;
            $high = $openEnd ? $high : $end;

            // El extremo abierto no puede quedar al revés que el escrito.
            if ($low > $high) {
                if ($openStart) {
                    $low = self::MIN;
                } else {
                    $high = self::MAX;
                }
            }
        }

        return new DateRange(
            Marker::fromTimestamp(max(self::MIN, $low)),
            Marker::fromTimestamp(min(self::MAX, $high)),
        );
    }

    /**
     * Una fecha que llega de fuera (`?date=`, `goToDate()`): `YYYY-MM-DD` real
     * (no un 31 de febrero) y dentro de la ventana. Nada de lo que `Carbon` entiende
     * y esto no: `+999999 years`, `@999999999999`, `10000-01-01`.
     */
    public static function acceptsDate(mixed $date, ?DateRange $valid = null): bool
    {
        // `\z` y no `$`: este último deja pasar un salto de línea al final.
        if (! is_string($date) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $m)) {
            return false;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }

        return self::window($valid)->contains(Marker::of((int) $m[1], (int) $m[2], (int) $m[3]));
    }
}
