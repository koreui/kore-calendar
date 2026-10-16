<?php

namespace KoreUi\Calendar\ICal;

use DateTimeZone;

/**
 * Las zonas que usa un archivo y los años en que las usa, para escribir al final
 * un VTIMEZONE por zona que cubra justo ese rango.
 *
 * @internal
 */
final class ZoneUsage
{
    /** @var array<string, array{zone: DateTimeZone, min: int, max: int}> */
    private array $zones = [];

    public function note(string $tzid, DateTimeZone $zone, int $year): void
    {
        $this->zones[$tzid] ??= ['zone' => $zone, 'min' => $year, 'max' => $year];
        $this->zones[$tzid]['min'] = min($this->zones[$tzid]['min'], $year);
        $this->zones[$tzid]['max'] = max($this->zones[$tzid]['max'], $year);
    }

    /**
     * Ordenados por TZID: la misma entrada da el mismo archivo, sea cual sea el orden en
     * que aparecieron los eventos.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        ksort($this->zones);
        $lines = [];

        foreach ($this->zones as $tzid => $usage) {
            array_push($lines, ...TimezoneWriter::build($tzid, $usage['zone'], $usage['min'], $usage['max']));
        }

        return $lines;
    }
}
