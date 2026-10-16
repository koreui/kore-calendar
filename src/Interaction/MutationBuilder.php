<?php

namespace KoreUi\Calendar\Interaction;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;
use KoreUi\Calendar\View\CalendarSettings;

/**
 * De «el usuario soltó el evento ahí» a la mutación exacta que hay que guardar.
 *
 * Puro: recibe la instancia original y el destino, y devuelve un `EventMutation`
 * sin tocar la base de datos, la configuración ni la red. Las reglas son las de
 * `EventDragging.computeEventMutation` y `EventResizing.computeMutation` de
 * FullCalendar (fc-edge-cases 4.3 y 4.4):
 *
 * - El destino es el INICIO del evento, no el punto donde se agarró (#7533); el
 *   delta sale de restar el inicio original.
 * - Al cambiar de modalidad (día completo ↔ con hora) el fin deja de valer: sin
 *   `allDayMaintainDuration` se usa la duración por defecto de la modalidad nueva;
 *   con ella, se conserva la duración (a días enteros al ir a día completo, con
 *   un mínimo de un día).
 * - Lo que manda el navegador se vuelve a ajustar aquí: nunca se confía en que
 *   el cliente haya hecho el snap.
 * - La duración real de un evento con instantes exactos se conserva en INSTANTES,
 *   no en markers: una hora de reloj que cruza el cambio de horario no mide lo
 *   mismo en pared (01:30 → 03:30 son dos horas de pared pero una real).
 */
final class MutationBuilder
{
    public function __construct(
        private readonly WallClock $clock,
        private readonly int $snapSeconds,
        /** Hora a la que empieza la columna de cada día: la rejilla de snap cuenta desde ahí. */
        private readonly int $slotMinSeconds = 0,
        private readonly int $defaultTimedSeconds = 3600,
        private readonly int $defaultAllDayDays = 1,
        private readonly bool $allDayMaintainDuration = false,
    ) {
        if ($snapSeconds < 1) {
            throw new InvalidArgumentException("El snap debe ser de al menos 1 segundo (llegó {$snapSeconds}).");
        }

        if ($defaultTimedSeconds < 1 || $defaultAllDayDays < 1) {
            throw new InvalidArgumentException('Las duraciones por defecto de un evento deben ser de al menos 1 segundo y 1 día.');
        }
    }

    public static function fromSettings(CalendarSettings $settings): self
    {
        return new self(
            $settings->clock,
            $settings->snapSeconds,
            $settings->layoutOptions->slotMin,
            $settings->defaultTimedSeconds,
            $settings->defaultAllDayDays,
            $settings->allDayMaintainDuration,
        );
    }

    // ── Mover ───────────────────────────────────────────────────────────

    /**
     * @param  Marker  $destination  Hora de pared donde cae el INICIO del evento.
     * @param  bool  $allDay  Modalidad del destino (el carril de todo el día, o una celda con hora).
     * @param  list<string|int>|null  $resourceIds  Los recursos resultantes; `null` = no cambian.
     */
    public function move(EventInstance $original, Marker $destination, bool $allDay, ?array $resourceIds = null): EventMutation
    {
        $range = $original->range;
        $start = $allDay ? $destination->startOfDay() : $this->timedDestination($original, $destination);
        $explicitEnd = true;

        if ($original->allDay && $allDay) {
            // Día → día: el rango entero se desplaza, con los mismos días.
            $startMarker = $start;
            $endMarker = $start->addSeconds($range->seconds());
            $startInstant = $this->clock->toInstant($startMarker);
            $endInstant = $this->clock->toInstant($endMarker);
            $explicitEnd = $original->hasEnd;
        } elseif ($allDay) {
            // Con hora → día: sin mantener, el fin pasa a ser la duración por defecto
            // de un evento de día completo. Manteniendo, los días enteros que cubría.
            $days = $this->allDayMaintainDuration ? max(1, intdiv($range->seconds(), Marker::DAY)) : $this->defaultAllDayDays;
            $startMarker = $start;
            $endMarker = $start->addDays($days);
            $startInstant = $this->clock->toInstant($startMarker);
            $endInstant = $this->clock->toInstant($endMarker);
        } elseif ($original->allDay) {
            // Día → con hora: sin mantener, la duración por defecto de un evento con
            // hora, en tiempo real (una hora tras 01:30 la noche del cambio no son las
            // 02:30 de pared). Manteniendo, el mismo rango de pared (3-sep → 5-sep
            // movido a las 02:00 acaba el 5-sep a las 02:00).
            $startInstant = $this->clock->toInstant($start);
            $startMarker = $this->clock->toMarker($startInstant);
            $endInstant = $this->allDayMaintainDuration
                ? $this->clock->toInstant($start->addSeconds($range->seconds()))
                : $this->plus($startInstant, $this->defaultTimedSeconds);
            $endMarker = $this->endMarker($startMarker, $startInstant, $endInstant);
        } else {
            // Con hora → con hora: la duración REAL se conserva sumándola en instantes.
            $unchanged = $start->equals($range->start);

            // Si el inicio no cambia se conserva su instante: en el pliegue de otoño
            // dos instantes comparten marker y `toInstant()` solo sabe elegir el primero.
            $startInstant = $unchanged && $original->exactStart !== null ? $original->exactStart : $this->clock->toInstant($start);
            $startMarker = $unchanged ? $range->start : $this->clock->toMarker($startInstant);
            $endInstant = $original->exactStart !== null && $original->exactEnd !== null
                ? $this->plus($startInstant, $original->exactEnd->getTimestamp() - $original->exactStart->getTimestamp())
                : $this->clock->toInstant($start->addSeconds($range->seconds()));
            $endMarker = $this->endMarker($startMarker, $startInstant, $endInstant);
            $explicitEnd = $original->hasEnd;
        }

        return $this->mutation(
            $original, $allDay, $startMarker, $startInstant, $endMarker, $endInstant, $explicitEnd,
            $resourceIds ?? $original->event->resourceIds,
            $startMarker->ts - $range->start->ts,
        );
    }

    /**
     * El inicio de un destino con hora, ya ajustado al snap.
     *
     * Soltar el evento en la celda donde ya estaba no lo mueve (FullCalendar:
     * delta 0 → sin mutación). Sin esta regla, un evento a las 10:10 con snap de
     * 15 min «saltaría» a las 10:15 al arrastrarlo de una sala a otra a la misma
     * hora, o al soltarlo donde estaba: el cliente manda el inicio de la celda,
     * no el minuto exacto. La celda se identifica por el SUELO de la rejilla, que
     * es como la calcula el cliente (`floor(parcial · snapsPerSlot)`).
     */
    private function timedDestination(EventInstance $original, Marker $destination): Marker
    {
        if (! $original->allDay && $this->grid($destination, false)->equals($this->grid($original->range->start, false))) {
            return $original->range->start;
        }

        return $this->grid($destination, true);
    }

    // ── Redimensionar ───────────────────────────────────────────────────

    /**
     * @param  Marker  $edge  El borde nuevo: el fin (exclusivo) o, con `$fromStart`, el inicio.
     * @return EventMutation|null `null` si la duración quedaría en cero o negativa: no hay
     *                            mutación posible (FullCalendar tampoco la produce).
     */
    public function resize(EventInstance $original, Marker $edge, bool $fromStart = false): ?EventMutation
    {
        $range = $original->range;

        if ($original->allDay) {
            // El borde de un evento de día completo es un día entero, siempre.
            $edge = $edge->startOfDay();
            $startMarker = $fromStart ? $edge : $range->start;
            $endMarker = $fromStart ? $range->end : $edge;

            if ($endMarker->ts <= $startMarker->ts) {
                return null;
            }

            $startInstant = $this->clock->toInstant($startMarker);
            $endInstant = $this->clock->toInstant($endMarker);
        } else {
            $edge = $this->grid($edge, true);

            // El borde que no se toca conserva su instante exacto si lo tiene.
            $startInstant = $fromStart ? $this->clock->toInstant($edge) : ($original->exactStart ?? $this->clock->toInstant($range->start));
            $endInstant = $fromStart ? ($original->exactEnd ?? $this->clock->toInstant($range->end)) : $this->clock->toInstant($edge);

            // Se compara en instantes: en el pliegue, 02:30+01:00 va después de 02:30+02:00.
            if ($endInstant->getTimestamp() <= $startInstant->getTimestamp()) {
                return null;
            }

            $startMarker = $fromStart ? $this->clock->toMarker($startInstant) : $range->start;
            $endMarker = $this->endMarker($startMarker, $startInstant, $endInstant);
        }

        return $this->mutation(
            $original, $original->allDay, $startMarker, $startInstant, $endMarker, $endInstant,
            // Redimensionar le da un fin explícito a un evento que no lo tenía
            // (FullCalendar fuerza `hasEnd` en cuanto hay startDelta/endDelta).
            true,
            $original->event->resourceIds,
            $fromStart ? $startMarker->ts - $range->start->ts : $endMarker->ts - $range->end->ts,
        );
    }

    // ── Seleccionar ─────────────────────────────────────────────────────

    /**
     * Un rango arrastrado sobre la rejilla. Los dos bordes se ordenan (arrastrar
     * hacia arriba los da al revés, `joinHitsIntoSelection`) y se ajustan; el
     * fin es exclusivo.
     *
     * Un rango que el ajuste deja vacío se amplía a una unidad (un día, o un
     * snap): una selección sin tamaño no se puede dibujar ni abrir en un formulario.
     */
    public function select(Marker $a, Marker $b, bool $allDay, string|int|null $resourceId = null): Selection
    {
        [$from, $to] = $a->ts <= $b->ts ? [$a, $b] : [$b, $a];

        if ($allDay) {
            $startMarker = $from->startOfDay();
            $endMarker = $to->startOfDay();
            $endMarker = $endMarker->ts > $startMarker->ts ? $endMarker : $startMarker->addDays(1);

            return new Selection(
                $this->clock->toInstant($startMarker), $this->clock->toInstant($endMarker),
                $startMarker, $endMarker, true, $resourceId,
            );
        }

        $startInstant = $this->clock->toInstant($this->grid($from, true));
        $startMarker = $this->clock->toMarker($startInstant);
        $endInstant = $this->clock->toInstant($this->grid($to, true));

        if ($endInstant->getTimestamp() <= $startInstant->getTimestamp()) {
            $endInstant = $this->plus($startInstant, $this->snapSeconds);
        }

        return new Selection(
            $startInstant, $endInstant, $startMarker,
            $this->endMarker($startMarker, $startInstant, $endInstant), false, $resourceId,
        );
    }

    // ── Formulario de evento ────────────────────────────────────────────

    /**
     * Lo que escribió el usuario en el formulario al editar, como la mutación que
     * habría sido arrastrarlo: así se valida con las mismas reglas (`MutationValidator::validateEdit()`).
     *
     * A diferencia de un arrastre, aquí no hay snap: el usuario escribió la hora
     * que quiso. El fin que falta (`null`) es el de por defecto de la modalidad, el
     * mismo que le pone el normalizador, para que un evento sin fin al que no se le toca
     * nada no cuente como cambiado.
     *
     * @param  Marker  $start  Hora de pared del inicio (con `$allDay`, el día).
     * @param  Marker|null  $end  Hora de pared del fin, exclusivo. `null` si el formulario no lo trae.
     * @param  list<string|int>  $resourceIds  Los recursos resultantes.
     */
    public function edit(EventInstance $original, Marker $start, ?Marker $end, bool $allDay, array $resourceIds): EventMutation
    {
        [$startMarker, $startInstant, $endMarker, $endInstant] = $this->formRange($start, $end, $allDay);

        return $this->mutation(
            $original, $allDay, $startMarker, $startInstant, $endMarker, $endInstant, $end !== null,
            $resourceIds, $startMarker->ts - $original->range->start->ts,
        );
    }

    /**
     * Lo que escribió el usuario en el formulario de un evento nuevo, como la selección
     * que habría sido arrastrarla (`MutationValidator::validateSelectionRules()`).
     */
    public function draft(Marker $start, ?Marker $end, bool $allDay, string|int|null $resourceId = null): Selection
    {
        [$startMarker, $startInstant, $endMarker, $endInstant] = $this->formRange($start, $end, $allDay);

        return new Selection($startInstant, $endInstant, $startMarker, $endMarker, $allDay, $resourceId);
    }

    /**
     * El inicio y el fin efectivos de lo que llega del formulario, en marker e instante.
     *
     * @return array{0: Marker, 1: CarbonImmutable, 2: Marker, 3: CarbonImmutable}
     */
    private function formRange(Marker $start, ?Marker $end, bool $allDay): array
    {
        if ($allDay) {
            $startMarker = $start->startOfDay();
            $endMarker = $end?->startOfDay();

            if ($endMarker === null || $endMarker->ts <= $startMarker->ts) {
                $endMarker = $startMarker->addDays($this->defaultAllDayDays);
            }

            return [$startMarker, $this->clock->toInstant($startMarker), $endMarker, $this->clock->toInstant($endMarker)];
        }

        $startInstant = $this->clock->toInstant($start);
        $startMarker = $this->clock->toMarker($startInstant);
        $endInstant = $end === null ? null : $this->clock->toInstant($end);

        // Sin fin, o con un fin que no queda después del inicio: la duración por defecto.
        if ($endInstant === null || $endInstant->getTimestamp() <= $startInstant->getTimestamp()) {
            $endInstant = $this->plus($startInstant, $this->defaultTimedSeconds);
        }

        return [$startMarker, $startInstant, $this->endMarker($startMarker, $startInstant, $endInstant), $endInstant];
    }

    // ── Recursos ────────────────────────────────────────────────────────

    /**
     * Los recursos de un evento tras soltarlo en la columna (o fila) `$to`.
     *
     * - Con `$from` (de qué recurso se agarró): sustituye ese id por `$to`. Un
     *   evento con varios recursos aparece una vez por cada uno, y moverlo
     *   desde la columna A no debe quitarle la B.
     * - Sin `$from`: solo es inequívoco con ningún recurso o con uno (se asigna
     *   o se sustituye). Con varios, si ya está en `$to` no cambia nada (es un
     *   movimiento de hora dentro de su columna); si no, no hay forma de saber
     *   cuál de ellos se quería cambiar.
     *
     * @param  list<string|int>  $current
     * @return list<string|int>|null `null` si el cambio no se puede interpretar (un `$from`
     *                               que el evento no tiene, o varios recursos sin `$from`).
     */
    public static function resourcesAfterDrop(array $current, string|int|null $to, string|int|null $from = null): ?array
    {
        if ($to === null) {
            return $current;
        }

        if ($from !== null) {
            $found = false;
            $next = [];

            foreach ($current as $id) {
                if ((string) $id === (string) $from) {
                    $found = true;
                    $id = $to;
                }

                $next[(string) $id] ??= $id;
            }

            return $found ? array_values($next) : null;
        }

        if (count($current) <= 1) {
            return [$to];
        }

        foreach ($current as $id) {
            if ((string) $id === (string) $to) {
                return $current;
            }
        }

        return null;
    }

    // ── Rejilla de snap ─────────────────────────────────────────────────

    /**
     * Un marker ajustado a la rejilla del snap (el más cercano) o, con
     * `$nearest = false`, el escalón que lo contiene.
     *
     * La rejilla cuenta desde `slot_min_time`, no desde las 00:00: es de donde la
     * cuenta el cliente (`inicio = día + slotMinTime + snap · índice`, FullCalendar
     * `queryHit`). Con un eje que empieza a las 08:10 y un snap de 30 min, los
     * escalones son 08:10, 08:40…; contarlos desde medianoche los desplazaría 10
     * minutos. Con los ejes habituales (que empiezan en una hora en punto) da lo
     * mismo.
     */
    public function snapTimed(Marker $marker): Marker
    {
        return $this->grid($marker, true);
    }

    private function grid(Marker $marker, bool $nearest): Marker
    {
        $relative = $marker->secondsOfDay() - $this->slotMinSeconds;
        $steps = self::floorDiv($nearest ? $relative + intdiv($this->snapSeconds, 2) : $relative, $this->snapSeconds);

        return $marker->startOfDay()->addSeconds($this->slotMinSeconds + $steps * $this->snapSeconds);
    }

    /** División entera hacia −∞ (`intdiv` redondea hacia 0 y los segundos relativos pueden ser negativos). */
    private static function floorDiv(int $a, int $b): int
    {
        return intdiv($a - ((($a % $b) + $b) % $b), $b);
    }

    // ── Piezas comunes ──────────────────────────────────────────────────

    /**
     * El fin como marker. Cumple `fin > inicio` siempre: si la hora de pared del
     * fin cae en el mismo marker que el inicio o antes (un rango real que el
     * pliegue de otoño comprime: 02:30+02:00 → 02:30+01:00), se expresa como
     * inicio + duración real. Es la misma regla de `EventNormalizer::timed()`.
     */
    private function endMarker(Marker $startMarker, CarbonImmutable $startInstant, CarbonImmutable $endInstant): Marker
    {
        $end = $this->clock->toMarker($endInstant);

        return $end->ts > $startMarker->ts
            ? $end
            : $startMarker->addSeconds(max(1, $endInstant->getTimestamp() - $startInstant->getTimestamp()));
    }

    /** Segundos de reloj (no de pared) sumados a un instante. */
    private function plus(CarbonImmutable $instant, int $seconds): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($instant->getTimestamp() + $seconds, $this->clock->zone);
    }

    /**
     * @param  list<string|int>  $resourceIds
     */
    private function mutation(
        EventInstance $original,
        bool $allDay,
        Marker $startMarker,
        CarbonImmutable $startInstant,
        Marker $endMarker,
        CarbonImmutable $endInstant,
        bool $explicitEnd,
        array $resourceIds,
        int $deltaSeconds,
    ): EventMutation {
        $range = $original->range;

        return new EventMutation(
            event: $original->event,
            start: $startInstant,
            end: $explicitEnd ? $endInstant : null,
            allDay: $allDay,
            resourceIds: $resourceIds,
            previousStart: $original->exactStart ?? $this->clock->toInstant($range->start),
            previousEnd: $original->hasEnd ? ($original->exactEnd ?? $this->clock->toInstant($range->end)) : null,
            previousAllDay: $original->allDay,
            previousResourceIds: $original->event->resourceIds,
            startMarker: $startMarker,
            endMarker: $endMarker,
            deltaSeconds: $deltaSeconds,
            endInstant: $endInstant,
            previousStartMarker: $range->start,
            previousEndMarker: $range->end,
            // Qué ocurrencia es, si lo es: el consumidor no puede deducirlo del id.
            seriesId: $original->seriesId,
            recurrenceId: $original->recurrenceId,
            occurrenceStart: $original->occurrenceStart,
        );
    }
}
