<?php

namespace KoreUi\Calendar\Interaction;

use KoreUi\Calendar\Availability\BusinessHours;
use KoreUi\Calendar\Availability\Collision;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\View\CalendarSettings;

/**
 * Decide si una mutación ya construida se puede guardar (docs/calendar.md §11,
 * fc-edge-cases 4.5). Devuelve `null` si es válida o el motivo del rechazo, ya
 * traducido y listo para un toast.
 *
 * Las comprobaciones van de la más barata y más clara a la más cara, y paran en
 * la primera que falla: editabilidad, rango válido, horario laboral, solapes. No
 * hay `eventAllow` ni un `eventConstraint` con funciones, grupos o rangos (en
 * FullCalendar son funciones JS): lo único que se declara es `'businessHours'`, y la
 * regla de negocio que no cabe aquí va en `persistMove()` / `persistResize()`, que
 * pueden rechazar con un texto.
 *
 * El horario laboral y los solapes de una selección necesitan saber más que la
 * mutación: `$resources` (el horario de CADA recurso gana al del calendario) y, para
 * seleccionar, los eventos de la vista. Sin `$resources` solo manda el del calendario.
 */
final class MutationValidator
{
    /**
     * La clave del rechazo por solapar un evento que no se dibuja: «Se solapa con otro
     * evento», sin su título, que la vista no muestra.
     */
    private const HIDDEN_OVERLAP = 'overlap_rejected_hidden';

    private bool $checkHours = true;

    public function __construct(
        private readonly CalendarSettings $settings,
        private readonly ?ResourceSet $resources = null,
    ) {}

    /**
     * El mismo validador sin la regla del horario laboral. Deshacer lo usa: devuelve el
     * evento a donde estaba, y si ese sitio ya estaba fuera de horario (datos importados,
     * un evento creado antes de que hubiera horario) rechazarlo dejaría al usuario sin
     * poder deshacer un cambio que acaba de hacer. Si estaba bien, tampoco hace falta.
     */
    public function ignoringBusinessHours(): self
    {
        $copy = clone $this;
        $copy->checkHours = false;

        return $copy;
    }

    /**
     * @param  iterable<EventInstance>  $instances  Los eventos de la vista, incluido el que se mueve.
     */
    public function validateMove(EventMutation $mutation, iterable $instances): ?string
    {
        if (! Editability::canMove($mutation->event, $this->settings)) {
            return $this->text('not_editable');
        }

        if ($mutation->isResourceChange() && ! Editability::canChangeResource($mutation->event, $this->settings)) {
            return $this->text('resource_locked');
        }

        return $this->rangeError($mutation) ?? $this->hoursError($mutation) ?? $this->overlapError($mutation, $instances);
    }

    /**
     * @param  iterable<EventInstance>  $instances
     */
    public function validateResize(EventMutation $mutation, iterable $instances): ?string
    {
        if (! Editability::canResize($mutation->event, $this->settings)) {
            return $this->text('not_editable');
        }

        return $this->rangeError($mutation) ?? $this->hoursError($mutation) ?? $this->overlapError($mutation, $instances);
    }

    /**
     * Un evento editado en el formulario. Las mismas reglas que al arrastrarlo, aplicadas
     * a lo que el usuario cambió y no a todo: mover el inicio (o la modalidad, o el
     * recurso) pide `canMove`, cambiar la duración pide `canResize` y cambiar de recurso
     * pide además `canChangeResource`. Después, rango válido, horario laboral y solapes.
     *
     * Un cambio que no toca fechas ni recurso (el título, el color) no pasa por aquí: no hay
     * nada que validar, y un evento ya fuera de horario no debe poder editarse por eso.
     *
     * @param  iterable<EventInstance>  $instances  Los eventos de la vista, incluido el que se edita.
     */
    public function validateEdit(EventMutation $mutation, iterable $instances): ?string
    {
        if ($mutation->isNoop()) {
            return null;
        }

        $moved = $mutation->isAllDayChange()
            || $mutation->isResourceChange()
            || ! $mutation->startMarker->equals($mutation->previousStartMarker);
        $resized = $mutation->endMarker->ts - $mutation->startMarker->ts
            !== $mutation->previousEndMarker->ts - $mutation->previousStartMarker->ts;

        if (($moved && ! Editability::canMove($mutation->event, $this->settings))
            || ($resized && ! Editability::canResize($mutation->event, $this->settings))) {
            return $this->text('not_editable');
        }

        if ($mutation->isResourceChange() && ! Editability::canChangeResource($mutation->event, $this->settings)) {
            return $this->text('resource_locked');
        }

        return $this->rangeError($mutation) ?? $this->hoursError($mutation) ?? $this->overlapError($mutation, $instances);
    }

    /**
     * Una selección tiene que caer dentro del rango válido. Es lo único que
     * comprueba el formulario de evento al crear (con `validateSelectionRules()`) y
     * al editar su rango: editar un evento existente no puede rechazarse por
     * solaparse consigo mismo.
     */
    public function validateSelection(Selection $selection): ?string
    {
        return $this->withinRange($selection->startMarker->ts, $selection->endMarker->ts);
    }

    /**
     * Lo que además exige SELECCIONAR un rango (`selectConstraint`, `selectOverlap`).
     * Aparte de `validateSelection()` porque necesita los eventos de la vista, y
     * `KoreCalendar` solo los pide si hay una regla que los use.
     *
     * @param  iterable<EventInstance>  $instances
     */
    public function validateSelectionRules(Selection $selection, iterable $instances = []): ?string
    {
        $outsideHours = $this->settings->selectConstraint === 'businessHours'
            ? $this->hoursFor($selection->startMarker, $selection->endMarker, $selection->allDay, $selection->resourceId === null ? [] : [$selection->resourceId])
            : null;

        return $outsideHours ?? $this->selectionOverlapError($selection, $instances);
    }

    private function rangeError(EventMutation $mutation): ?string
    {
        return $this->withinRange($mutation->startMarker->ts, $mutation->endMarker->ts);
    }

    /**
     * El rango ENTERO tiene que caber, no solo el inicio: un evento que empieza
     * dentro y acaba fuera también se sale del rango.
     *
     * Cabe en `Bounds::window()`: la ventana por defecto, o el `valid_range` del
     * consumidor si lo hay (que la sustituye).
     */
    private function withinRange(int $start, int $end): ?string
    {
        // Los límites duros primero: con un entero cualquiera ni siquiera se construye el rango.
        if (! Bounds::contains($start, $end)) {
            return $this->text('out_of_range');
        }

        $window = Bounds::window($this->settings->validRange);

        if ($start < $window->start->ts || $end > $window->end->ts) {
            return $this->text('out_of_range');
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

        foreach ($instances as $other) {
            $event = $other->event;

            if ((string) $event->id === (string) $subject->id) {
                continue;
            }

            if (Collision::sameGroup($subject, $event) || ! Collision::blocks($subjectBlocks, $event)) {
                continue;
            }

            if (! Collision::sharesLane($mutation->resourceIds, $event->resourceIds) || ! self::intersects($mutation, $other)) {
                continue;
            }

            return $this->overlapText($event);
        }

        return null;
    }

    /**
     * `selectOverlap(false)`: no se puede seleccionar un rango que pisa un evento. Mismas
     * reglas que al soltar un evento, con la selección como quien bloquea (no es un
     * evento: no tiene id, ni grupo, ni `overlap` propio). En una vista por recurso
     * solo cuentan los eventos de esa columna o fila; en una vista sin recursos todos
     * comparten la única columna que hay.
     *
     * @param  iterable<EventInstance>  $instances
     */
    private function selectionOverlapError(Selection $selection, iterable $instances): ?string
    {
        if ($this->settings->selectOverlap) {
            return null;
        }

        $lane = $selection->resourceId === null ? [] : [$selection->resourceId];

        foreach ($instances as $other) {
            if (! Collision::blocks(true, $other->event)) {
                continue;
            }

            if ($lane !== [] && ! Collision::sharesLane($lane, $other->event->resourceIds)) {
                continue;
            }

            $exact = ! $selection->allDay;

            if (Collision::intersects($selection->startMarker, $selection->endMarker, $exact ? $selection->start : null, $exact ? $selection->end : null, $other)) {
                return $this->overlapText($other->event);
            }
        }

        return null;
    }

    /**
     * `eventConstraint('businessHours')`: el evento tiene que quedar dentro del
     * horario laboral de CADA recurso al que pertenece tras el cambio (o del
     * calendario, si no tiene recursos).
     */
    private function hoursError(EventMutation $mutation): ?string
    {
        if (! $this->checkHours || $this->settings->eventConstraint !== 'businessHours') {
            return null;
        }

        return $this->hoursFor($mutation->startMarker, $mutation->endMarker, $mutation->allDay, $mutation->resourceIds);
    }

    /**
     * El rango cabe en el horario de cada recurso destino. Un evento de día completo
     * no tiene hora, así que no se le pide «caber» en 09:00-17:00: se le pide caer en
     * días en que se trabaja (`coversDays()`).
     *
     * @param  list<string|int>  $resourceIds
     */
    private function hoursFor(Marker $start, Marker $end, bool $allDay, array $resourceIds): ?string
    {
        if ($end->ts <= $start->ts) {
            return null;
        }

        $span = new DateRange($start, $end);
        $targets = $resourceIds === [] ? [null] : array_map(fn ($id) => $this->resources?->find($id), $resourceIds);

        foreach ($targets as $resource) {
            $hours = BusinessHours::forResource($resource, $this->settings->businessHours);

            if ($allDay ? $hours->coversDays($span) : $hours->covers($span)) {
                continue;
            }

            return $resource === null
                ? $this->text('outside_business_hours')
                : $this->text('outside_business_hours_resource', ['resource' => $resource->title]);
        }

        return null;
    }

    /**
     * El motivo de un solape. Nombra al otro evento solo si se dibuja: el título de uno
     * de fondo o `display: none` no lo ve nadie, y un rechazo no puede ser la forma de
     * leerlo (el usuario podría sondear con movimientos y recorrer lo que hay detrás).
     */
    private function overlapText(CalendarEvent $other): string
    {
        return Collision::occupies($other)
            ? $this->text('overlap_rejected', ['title' => $other->title])
            : $this->text(self::HIDDEN_OVERLAP);
    }

    /** El motivo en el idioma del calendario. */
    private function text(string $key, array $replace = []): string
    {
        return Messages::get($key, $replace, $this->settings->locale);
    }

    /** Semiabierto y con instantes exactos cuando los hay: ver `Collision::intersects()`. */
    private static function intersects(EventMutation $mutation, EventInstance $other): bool
    {
        return Collision::intersects(
            $mutation->startMarker, $mutation->endMarker,
            $mutation->allDay ? null : $mutation->start, $mutation->allDay ? null : $mutation->endInstant,
            $other,
        );
    }
}
