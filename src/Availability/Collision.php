<?php

namespace KoreUi\Calendar\Availability;

use Carbon\CarbonInterface;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Time\Marker;

/**
 * Las reglas de solape de `isInteractionPropsValid` de FullCalendar, en un solo
 * sitio. Las usan `MutationValidator` (¿se puede soltar aquí?), `ConflictFinder`
 * (¿qué eventos ya se pisan?) y `AvailabilityFinder` (¿qué está ocupado?): si cada
 * uno las tuviera por su cuenta acabarían discrepando, y el calendario marcaría
 * como conflicto algo que el servidor deja mover, o al revés.
 *
 * Sin estado ni dependencias: cada método responde una pregunta sobre eventos.
 */
final class Collision
{
    /**
     * Ocupa sitio: un evento de fondo (`display: background` o `none`) no, y por eso no
     * bloquea por la regla general. Ver `blocks()` para cuándo sí.
     */
    public static function occupies(CalendarEvent $event): bool
    {
        return ! in_array($event->display, ['background', 'none'], true);
    }

    /**
     * Los eventos del mismo grupo se mueven juntos y no colisionan entre sí. Sin
     * `groupId` (`null`) no hay grupo: dos eventos sin él no son del mismo.
     */
    public static function sameGroup(CalendarEvent $a, CalendarEvent $b): bool
    {
        return $a->groupId !== null && $a->groupId === $b->groupId;
    }

    /**
     * ¿`$other` impide que otro evento ocupe su sitio?
     *
     * - Si el que llega bloquea (`$subjectBlocks`: el calendario prohíbe solapes, o
     *   el evento tiene `overlap: false`), le molesta todo lo que ocupe sitio.
     * - Si `$other` dice `overlap: false`, bloquea a todos, incluso siendo de fondo:
     *   es la forma de marcar «aquí no se puede soltar nada» (FullCalendar, igual).
     */
    public static function blocks(bool $subjectBlocks, CalendarEvent $other): bool
    {
        return ($subjectBlocks && self::occupies($other)) || $other->overlap === false;
    }

    /**
     * Lo mismo entre dos eventos, sin saber cuál «llega»: hay conflicto si alguno de
     * los dos bloquea al otro. Solo puede «llegar» un evento que ocupa sitio: uno de
     * fondo no se arrastra ni se coloca, y si contara como quien llega bloquearía a
     * todo lo que pisa (lo contrario de lo que dice `blocks()`).
     */
    public static function conflict(CalendarEvent $a, CalendarEvent $b, bool $calendarForbids): bool
    {
        return (self::occupies($a) && self::blocks($calendarForbids || $a->overlap === false, $b))
            || (self::occupies($b) && self::blocks($calendarForbids || $b->overlap === false, $a));
    }

    /**
     * Los «carriles» de un evento: sus recursos, o uno propio si no tiene. Un
     * evento sin recurso ocupa su propio carril, así que en un calendario sin
     * recursos todo comparte carril y la regla no cambia nada. '' nunca es un id
     * de recurso (CalendarEvent rechaza los vacíos).
     *
     * @param  list<string|int>  $resourceIds
     * @return list<string>
     */
    public static function lanes(array $resourceIds): array
    {
        return $resourceIds === [] ? [''] : array_map('strval', $resourceIds);
    }

    /**
     * @param  list<string|int>  $a
     * @param  list<string|int>  $b
     */
    public static function sharesLane(array $a, array $b): bool
    {
        return array_intersect(self::lanes($a), self::lanes($b)) !== [];
    }

    /**
     * Semiabierto: dos rangos que solo se tocan en el borde no se solapan. Con
     * instantes exactos en los dos lados se comparan instantes: en la hora repetida
     * del otoño, 02:30+02:00 y 02:30+01:00 tienen el mismo marker y compararlos por
     * pared daría un solape que no existe. El resto (día completo, hora sin offset)
     * se compara en markers.
     *
     * @param  ?CarbonInterface  $startAt  El instante del inicio, o `null` si es de día completo o de hora flotante.
     * @param  ?CarbonInterface  $endAt  Ídem para el fin.
     */
    public static function intersects(Marker $start, Marker $end, ?CarbonInterface $startAt, ?CarbonInterface $endAt, EventInstance $other): bool
    {
        if ($startAt !== null && $endAt !== null && ! $other->allDay && $other->exactStart !== null && $other->exactEnd !== null) {
            return $startAt->getTimestamp() < $other->exactEnd->getTimestamp()
                && $other->exactStart->getTimestamp() < $endAt->getTimestamp();
        }

        return $start->ts < $other->range->end->ts && $other->range->start->ts < $end->ts;
    }

    /** Dos instancias se solapan en el tiempo (con la precisión de `intersects()`). */
    public static function overlap(EventInstance $a, EventInstance $b): bool
    {
        return self::intersects(
            $a->range->start, $a->range->end,
            $a->allDay ? null : $a->exactStart, $a->allDay ? null : $a->exactEnd,
            $b,
        );
    }
}
