<?php

namespace KoreUi\Calendar\Interaction;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Time\Marker;

/**
 * Lo que un movimiento o un cambio de duración hace a un evento, ya ajustado al
 * snap y con las reglas de FullCalendar aplicadas. Es lo que recibe
 * `persistMove()` / `persistResize()`: el consumidor guarda estos valores y no
 * tiene que volver a razonar sobre días completos, pliegues de horario ni
 * recursos.
 *
 * - `start`, `end`: instantes en la zona de la vista, para guardar. En un
 *   evento de día completo son las 00:00 de esa fecha en la zona; para
 *   guardarlos como fecha sin hora, `startDate()` / `endDate()`.
 * - `end` es `null` solo cuando el evento no tenía fin y se movió sin cambiar de
 *   modalidad: se respeta la forma de sus datos (una columna `ends_at` NULL
 *   sigue en NULL). En cualquier otro caso el fin está materializado. El fin
 *   efectivo, siempre presente, es `endInstant`.
 * - `startMarker`, `endMarker`: la misma hora como hora de pared (lo que dibuja
 *   la rejilla). Cumplen `endMarker > startMarker` siempre, como `EventInstance`.
 * - `deltaSeconds`: cuánto se movió el borde que cambió, en segundos de marker:
 *   el inicio al mover, el borde arrastrado al redimensionar. En markers un día
 *   mide siempre 86 400 s, así que un movimiento de «+1 día» vale lo mismo la
 *   noche del cambio de horario.
 * - `previous*`: cómo estaba el evento antes, para auditoría o «deshacer».
 */
final readonly class EventMutation
{
    /**
     * @param  list<string|int>  $resourceIds
     * @param  list<string|int>  $previousResourceIds
     */
    public function __construct(
        public CalendarEvent $event,
        public CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public bool $allDay,
        public array $resourceIds,
        public CarbonImmutable $previousStart,
        public ?CarbonImmutable $previousEnd,
        public bool $previousAllDay,
        public array $previousResourceIds,
        public Marker $startMarker,
        public Marker $endMarker,
        public int $deltaSeconds,
        public CarbonImmutable $endInstant,
        public Marker $previousStartMarker,
        public Marker $previousEndMarker,
    ) {}

    /** Día del inicio, `Y-m-d`, en la zona de la vista. */
    public function startDate(): string
    {
        return $this->startMarker->toDateString();
    }

    /**
     * Día del fin (exclusivo en un evento de día completo), `Y-m-d`, o `null`
     * si el evento se queda sin fin.
     */
    public function endDate(): ?string
    {
        return $this->end === null ? null : $this->endMarker->toDateString();
    }

    /** Cambia de recurso. Se compara como conjunto: el orden no es un cambio. */
    public function isResourceChange(): bool
    {
        return self::keys($this->resourceIds) !== self::keys($this->previousResourceIds);
    }

    /** Pasa de día completo a con hora, o al revés. */
    public function isAllDayChange(): bool
    {
        return $this->allDay !== $this->previousAllDay;
    }

    /**
     * No cambia nada que el usuario pueda ver: soltar el evento donde estaba.
     * Se compara en markers (lo que ve el usuario), no en instantes: dentro de
     * la hora repetida del otoño dos instantes tienen el mismo marker y el
     * cliente no tiene forma de elegir entre ellos.
     */
    public function isNoop(): bool
    {
        return $this->allDay === $this->previousAllDay
            && $this->startMarker->equals($this->previousStartMarker)
            && $this->endMarker->equals($this->previousEndMarker)
            && ! $this->isResourceChange();
    }

    /**
     * @param  list<string|int>  $ids
     * @return list<string>
     */
    private static function keys(array $ids): array
    {
        $keys = array_map('strval', $ids);
        sort($keys);

        return $keys;
    }
}
