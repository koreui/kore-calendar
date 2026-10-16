<?php

namespace KoreUi\Calendar\Time;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * La frontera entre instantes reales y horas de pared en la zona de la vista.
 *
 * Es el único sitio donde el calendario habla con zonas horarias. Todo lo que
 * entra (fechas de eventos, "ahora") se convierte aquí una vez a `Marker`, y lo
 * que sale hacia la base de datos (una mutación al soltar un evento) se
 * convierte de vuelta aquí.
 *
 * **La vuelta no se delega en PHP**, porque PHP no es consistente con las horas
 * repetidas: en Europe/Madrid `2026-10-25 02:30` sale como la SEGUNDA ocurrencia
 * (+01:00) y en America/New_York `2024-11-03 01:30` como la PRIMERA (−04:00).
 * Aquí la regla es fija, la de FullCalendar:
 *
 * - hora que no existe (salto de primavera): se resuelve hacia delante, con el
 *   offset de antes del salto (02:30 → 03:30 en Madrid);
 * - hora que existe dos veces (otoño): la primera ocurrencia.
 */
final class WallClock
{
    public readonly DateTimeZone $zone;

    public function __construct(DateTimeZone|string $zone)
    {
        $this->zone = $zone instanceof DateTimeZone ? $zone : new DateTimeZone($zone);
    }

    public function name(): string
    {
        return $this->zone->getName();
    }

    /**
     * Un instante → su hora de pared en la zona de la vista.
     *
     * Un string sin offset ('2026-10-16 10:00') se interpreta como hora de pared
     * en ESTA zona, igual que FullCalendar; uno con offset o 'Z' conserva su
     * instante. Una fecha PHP siempre es un instante (lleva su zona).
     */
    public function toMarker(DateTimeInterface|string $value): Marker
    {
        if (is_string($value)) {
            if (! self::hasOffset($value)) {
                return Marker::parse(str_replace('T', ' ', substr(trim($value), 0, 19)));
            }

            $value = new DateTimeImmutable($value);
        }

        return Marker::fromTimestamp($value->getTimestamp() + $this->zone->getOffset($value));
    }

    /** "Ahora" en la zona de la vista. Respeta `Carbon::setTestNow()`. */
    public function now(): Marker
    {
        return $this->toMarker(CarbonImmutable::now());
    }

    /** Una hora de pared → el instante, con las reglas de hueco y pliegue de arriba. */
    public function toInstant(Marker $marker): CarbonImmutable
    {
        $local = $marker->ts;

        // Offsets que la zona usa en ±2 días: los candidatos posibles.
        $offsets = [];
        foreach ($this->zone->getTransitions($local - 2 * Marker::DAY, $local + 2 * Marker::DAY) ?: [] as $t) {
            $offsets[$t['offset']] = true;
        }
        $offsets = array_keys($offsets) ?: [$this->zone->getOffset(new DateTimeImmutable('@'.$local))];

        // Un candidato es válido si, en ese instante, la zona usa justo ese offset.
        $validos = [];
        foreach ($offsets as $offset) {
            $instant = $local - $offset;

            if ($this->offsetAt($instant) === $offset) {
                $validos[] = $instant;
            }
        }

        if ($validos !== []) {
            // Pliegue: dos instantes válidos. El primero en el tiempo.
            return $this->carbon(min($validos));
        }

        // Hueco: ningún instante tiene esta hora de pared. Hacia delante: con el
        // offset de antes del salto, que es el más pequeño en primavera.
        return $this->carbon($local - min($offsets));
    }

    private function offsetAt(int $instant): int
    {
        return $this->zone->getOffset(new DateTimeImmutable('@'.$instant));
    }

    private function carbon(int $instant): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($instant, $this->zone);
    }

    private static function hasOffset(string $value): bool
    {
        return (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($value));
    }
}
