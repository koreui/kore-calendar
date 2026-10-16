<?php

namespace KoreUi\Calendar\Time;

use InvalidArgumentException;

/**
 * Qué día empieza la semana y cómo se numeran las semanas del año.
 *
 * Dos parámetros, los mismos que FullCalendar (`week.dow` / `week.doy` de cada
 * locale, en headless-calendar/src/env.ts) y que moment antes que él:
 *
 * - `dow`: primer día de la semana (0 = domingo … 6 = sábado).
 * - `doy`: define la semana 1. La semana 1 es la que contiene el día de enero
 *   número `7 + dow − doy`. ISO 8601 es dow 1, doy 4: la semana del jueves, o
 *   lo que es lo mismo, la que contiene el 4 de enero.
 *
 * `firstDay` de la configuración cambia SOLO `dow`, después de ISO, igual que en
 * FullCalendar: `firstDay: 2` con numeración ISO empieza en martes y sigue
 * numerando con la regla ISO.
 */
final class WeekRules
{
    /** Locales cuya semana empieza en domingo y cuenta la semana del 1 de enero (en: dow 0, doy 6). */
    private const SUNDAY_LOCALES = ['en', 'en_US', 'en_CA', 'es_MX', 'es_US', 'pt_BR', 'ja', 'ko', 'zh_TW', 'he', 'ar_SA'];

    public function __construct(
        public readonly int $dow = 1,
        public readonly int $doy = 4,
    ) {
        if ($dow < 0 || $dow > 6) {
            throw new InvalidArgumentException("El primer día de la semana tiene que ir de 0 (domingo) a 6 (sábado); llegó {$dow}.");
        }
    }

    public static function iso(): self
    {
        return new self(1, 4);
    }

    /** Las reglas del locale (por defecto, las europeas, que coinciden con ISO). */
    public static function forLocale(string $locale): self
    {
        $locale = str_replace('-', '_', $locale);

        return in_array($locale, self::SUNDAY_LOCALES, true) ? new self(0, 6) : self::iso();
    }

    /**
     * La combinación que usa el calendario.
     *
     * @param  'iso'|'locale'  $numbering
     */
    public static function resolve(?int $firstDay, string $numbering, string $locale): self
    {
        $base = $numbering === 'iso' ? self::iso() : self::forLocale($locale);

        return $firstDay === null ? $base : new self($firstDay, $base->doy);
    }

    public function startOfWeek(Marker $date): Marker
    {
        return $date->startOfWeek($this->dow);
    }

    /** Número de semana del año de la fecha (puede ser la 52/53 del año anterior o la 1 del siguiente). */
    public function weekNumber(Marker $date): int
    {
        $year = $date->year();
        $dayOfYear = (int) gmdate('z', $date->ts) + 1;
        // floor y no intdiv: el numerador es negativo en los primeros días de enero
        // que pertenecen a la última semana del año anterior.
        $week = (int) floor(($dayOfYear - $this->firstWeekOffset($year) - 1) / 7) + 1;

        if ($week < 1) {
            return $week + $this->weeksInYear($year - 1);
        }

        if ($week > $this->weeksInYear($year)) {
            return $week - $this->weeksInYear($year);
        }

        return $week;
    }

    public function weeksInYear(int $year): int
    {
        $days = (int) gmdate('z', gmmktime(0, 0, 0, 12, 31, $year)) + 1;

        return intdiv($days - $this->firstWeekOffset($year) + $this->firstWeekOffset($year + 1), 7);
    }

    /** Desfase (en días, desde el 1 de enero) del primer día de la semana 1. */
    private function firstWeekOffset(int $year): int
    {
        $fwd = 7 + $this->dow - $this->doy;
        $fwdlw = (7 + (int) gmdate('w', gmmktime(0, 0, 0, 1, $fwd, $year)) - $this->dow) % 7;

        return -$fwdlw + $fwd - 1;
    }
}
