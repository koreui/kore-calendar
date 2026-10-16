<?php

namespace KoreUi\Calendar\Interaction;

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\View\CalendarSettings;

/**
 * Decide si una mutación ya construida se puede guardar (docs/calendar.md §11,
 * fc-edge-cases 4.5). Devuelve `null` si es válida o el motivo del rechazo, ya
 * traducido y listo para un toast.
 *
 * Las comprobaciones van de la más barata y más clara a la más cara, y paran en
 * la primera que falla: editabilidad, rango válido, solapes. No hay `eventAllow`
 * ni `eventConstraint` (funciones JS en FullCalendar): la regla de negocio que
 * no cabe aquí va en `persistMove()` / `persistResize()`, que pueden rechazar
 * con un texto.
 */
final class MutationValidator
{
    public function __construct(private readonly CalendarSettings $settings) {}

    /**
     * @param  iterable<EventInstance>  $instances  Los eventos de la vista, incluido el que se mueve.
     */
    public function validateMove(EventMutation $mutation, iterable $instances): ?string
    {
        if (! Editability::canMove($mutation->event, $this->settings)) {
            return Messages::get('not_editable');
        }

        if ($mutation->isResourceChange() && ! Editability::canChangeResource($mutation->event, $this->settings)) {
            return Messages::get('resource_locked');
        }

        return $this->rangeError($mutation) ?? $this->overlapError($mutation, $instances);
    }

    /**
     * @param  iterable<EventInstance>  $instances
     */
    public function validateResize(EventMutation $mutation, iterable $instances): ?string
    {
        if (! Editability::canResize($mutation->event, $this->settings)) {
            return Messages::get('not_editable');
        }

        return $this->rangeError($mutation) ?? $this->overlapError($mutation, $instances);
    }

    /** Una selección solo tiene que caer dentro del rango válido. */
    public function validateSelection(Selection $selection): ?string
    {
        return $this->withinRange($selection->startMarker->ts, $selection->endMarker->ts);
    }

    private function rangeError(EventMutation $mutation): ?string
    {
        return $this->withinRange($mutation->startMarker->ts, $mutation->endMarker->ts);
    }

    /**
     * El rango ENTERO tiene que caber, no solo el inicio: un evento que empieza
     * dentro y acaba fuera también se sale del rango.
     */
    private function withinRange(int $start, int $end): ?string
    {
        if (! Bounds::contains($start, $end)) {
            return Messages::get('out_of_range');
        }

        $valid = $this->settings->validRange;

        if ($valid !== null && ! $valid->containsRange(new DateRange(Marker::fromTimestamp($start), Marker::fromTimestamp($end)))) {
            return Messages::get('out_of_range');
        }

        return null;
    }

    /**
     * Solapes, con las reglas de `isInteractionPropsValid` de FullCalendar:
     *
     * - Se excluyen el propio evento y los de su mismo `groupId`: un grupo se
     *   mueve junto y no colisiona consigo mismo.
     * - Hay conflicto si los intervalos semiabiertos se intersecan y (el
     *   calendario prohíbe solapes, o el evento movido tiene `overlap: false`)
     *   o el otro tiene `overlap: false`. Que dos eventos solo se toquen en un
     *   borde no es solaparse.
     * - Un evento de fondo (`display: background` o `none`) no ocupa sitio: no
     *   bloquea por la regla general. Sí bloquea si dice `overlap: false`, que
     *   es la forma de marcar «aquí no se puede soltar nada» (FullCalendar, igual).
     * - Con recursos, solo cuentan los que comparten alguno con el destino: dos
     *   reuniones a la misma hora en salas distintas no se pisan. Un evento sin
     *   recurso ocupa su propio «carril sin recurso», así que en un calendario
     *   sin recursos todo comparte carril y la regla no cambia nada.
     *
     * @param  iterable<EventInstance>  $instances
     */
    private function overlapError(EventMutation $mutation, iterable $instances): ?string
    {
        $subject = $mutation->event;
        $subjectBlocks = ! $this->settings->eventOverlap || $subject->overlap === false;
        $lanes = self::lanes($mutation->resourceIds);

        foreach ($instances as $other) {
            $event = $other->event;

            if ((string) $event->id === (string) $subject->id) {
                continue;
            }

            if ($subject->groupId !== null && $event->groupId === $subject->groupId) {
                continue;
            }

            $occupies = ! in_array($event->display, ['background', 'none'], true);

            if (! (($subjectBlocks && $occupies) || $event->overlap === false)) {
                continue;
            }

            if (array_intersect($lanes, self::lanes($event->resourceIds)) === [] || ! self::intersects($mutation, $other)) {
                continue;
            }

            return Messages::get('overlap_rejected', ['title' => $event->title]);
        }

        return null;
    }

    /**
     * @param  list<string|int>  $resourceIds
     * @return list<string>
     */
    private static function lanes(array $resourceIds): array
    {
        // '' nunca es un id de recurso (CalendarEvent rechaza los vacíos).
        return $resourceIds === [] ? [''] : array_map('strval', $resourceIds);
    }

    /**
     * Semiabierto. Con instantes exactos en los dos lados se comparan instantes:
     * en la hora repetida del otoño, 02:30+02:00 y 02:30+01:00 tienen el mismo
     * marker y compararlos por pared daría un solape que no existe. El resto
     * (día completo, hora sin offset) se compara en markers.
     */
    private static function intersects(EventMutation $mutation, EventInstance $other): bool
    {
        if (! $mutation->allDay && ! $other->allDay && $other->exactStart !== null && $other->exactEnd !== null) {
            return $mutation->start->getTimestamp() < $other->exactEnd->getTimestamp()
                && $other->exactStart->getTimestamp() < $mutation->endInstant->getTimestamp();
        }

        return $mutation->startMarker->ts < $other->range->end->ts
            && $other->range->start->ts < $mutation->endMarker->ts;
    }
}
