<?php

namespace KoreUi\Calendar\Recurrence;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Interaction\EventMutation;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;
use RRule\RRule;

/**
 * Las operaciones que el consumidor necesita para guardar el cambio de una serie
 * por alcance, sin saber RFC 5545. Puro y estático: recibe la serie (la que el
 * consumidor cargó con `$mutation->seriesId`) y la ocurrencia tocada, y devuelve
 * los valores a guardar. No consulta ni escribe nada.
 *
 * | Alcance     | Qué se guarda                                                    |
 * |-------------|------------------------------------------------------------------|
 * | `this`      | `override()` como evento nuevo (mover, editar) o `exclude()` en   |
 * |             | la serie (eliminar)                                              |
 * | `following` | `splitAt()`: la serie original se corta y nace otra desde aquí    |
 * | `all`       | `shift()` / `resize()` sobre la propia serie                     |
 *
 * **Todo se devuelve en la representación de la serie**, la misma con la que se
 * expande (`RecurrenceExpander`): una fecha 'Y-m-d' si es de día completo, una hora
 * de pared 'Y-m-d H:i:s' si es flotante (`start` sin zona) y un instante en la zona
 * de la serie si la tiene. Un EXDATE o un `recurrenceId` en otra representación
 * no coincidiría con la ocurrencia (FullCalendar #5726).
 *
 * La ocurrencia puede ser la `EventInstance` de la vista, la `EventMutation` que
 * llega a `persistMove()` / `persistResize()` o el array `$data` que llega a
 * `updateEvent()` / `deleteEvent()`: los tres llevan la misma identidad (`seriesId`,
 * `recurrenceId`, `occurrenceStart`) y cada hook solo tiene uno.
 */
final class RecurrenceEditor
{
    /**
     * Los EXDATE de la serie con esta ocurrencia añadida: «solo este evento» al eliminar.
     * (Mover o editar solo esa no lo necesita: la modificada de `override()` sustituye a
     * la generada por su `recurrenceId`.)
     *
     * Una ocurrencia que ya era una excepción también se excluye: borrar su fila no
     * basta, la generada volvería a aparecer.
     *
     * @param  EventInstance|EventMutation|array<string, mixed>  $occurrence  La instancia, la mutación o el `$data` de un hook.
     * @return list<string|DateTimeInterface>
     */
    public static function exclude(CalendarEvent $series, EventInstance|EventMutation|array $occurrence): array
    {
        [$recurrenceId, $occurrenceStart] = self::identify($series, $occurrence);
        [, $allDay, $zone] = self::shape($series);

        return [...array_values($series->exdates), self::represent($allDay, $recurrenceId, $occurrenceStart, $zone)];
    }

    /**
     * «Esta y las siguientes»: la regla de la serie original cortada justo ANTES de
     * la ocurrencia y la de la serie nueva que empieza en ella.
     *
     * - `before`: el `UNTIL` es un segundo antes de la ocurrencia, en UTC (RFC 5545
     *   lo exige cuando el inicio lleva hora; en una regla flotante «UTC» es la hora de
     *   pared, como en la expansión). Sustituye a `COUNT` y a `UNTIL`: dos de los tres
     *   no pueden convivir.
     * - `after`: con `COUNT`, las ocurrencias que quedaban desde esta, ella incluida
     *   (un EXDATE no resta del `COUNT`, igual que en la expansión); con `UNTIL` o sin
     *   límite, la misma regla.
     *
     * La serie nueva empieza en `start` = el de la ocurrencia (o donde la dejó el
     * movimiento) y la original se queda con `before`. La unión de las dos es
     * exactamente la serie original. Si la ocurrencia es la PRIMERA (`isFirst()`) no
     * queda nada antes: el corte dejaría una serie vacía, y lo que se quiere es
     * cambiarla entera.
     *
     * @param  EventInstance|EventMutation|array<string, mixed>  $occurrence  La instancia, la mutación o el `$data` de un hook.
     * @return array{before: string, after: string}
     */
    public static function splitAt(CalendarEvent $series, EventInstance|EventMutation|array $occurrence): array
    {
        $rule = self::ruleOf($series);
        [$recurrenceId, $occurrenceStart] = self::identify($series, $occurrence);
        [$first, , $zone] = self::shape($series);

        $cut = gmdate('Ymd\THis\Z', ($zone === null ? $recurrenceId->ts : $occurrenceStart->getTimestamp()) - 1);
        $before = [...self::withoutParts($rule, ['COUNT', 'UNTIL']), 'UNTIL='.$cut];

        $after = self::parts($rule);

        if (self::hasPart($rule, 'COUNT')) {
            $remaining = count(self::rrule($series, $first, $zone)->getOccurrencesBetween(self::instant($recurrenceId, $occurrenceStart, $zone), null));

            if ($remaining < 1) {
                throw new InvalidArgumentException("Evento «{$series->id}»: la ocurrencia no es una de las que genera su regla.");
            }

            $after = array_map(fn (string $part) => self::isPart($part, 'COUNT') ? 'COUNT='.$remaining : $part, $after);
        }

        return ['before' => implode(';', $before), 'after' => implode(';', $after)];
    }

    /**
     * La ocurrencia es la primera de la serie: no hay nada antes que conservar.
     * «Esta y las siguientes» sobre ella es «toda la serie».
     *
     * @param  EventInstance|EventMutation|array<string, mixed>  $occurrence  La instancia, la mutación o el `$data` de un hook.
     */
    public static function isFirst(CalendarEvent $series, EventInstance|EventMutation|array $occurrence): bool
    {
        self::ruleOf($series);
        [$recurrenceId, $occurrenceStart] = self::identify($series, $occurrence);
        [$first, , $zone] = self::shape($series);

        $start = self::rrule($series, $first, $zone)->getOccurrences(1)[0] ?? null;

        return $start !== null && $start->getTimestamp() === self::instant($recurrenceId, $occurrenceStart, $zone)->getTimestamp();
    }

    /**
     * El nuevo inicio y fin de la serie desplazados `$deltaSeconds`, que es
     * `$mutation->deltaSeconds` de un movimiento con alcance `all`.
     *
     * Los segundos son de hora de pared (un día mide siempre 86 400, también la noche
     * del cambio de horario) y se suman en la zona de la propia serie: «cada lunes a las
     * 9 de Madrid» movido un día es «cada martes a las 9 de Madrid».
     *
     * @return array{start: DateTimeInterface|string, end: DateTimeInterface|string|null}
     */
    public static function shift(CalendarEvent $series, int $deltaSeconds): array
    {
        return [
            'start' => self::shifted($series->start, $deltaSeconds),
            'end' => $series->end === null ? null : self::shifted($series->end, $deltaSeconds),
        ];
    }

    /**
     * El inicio y el fin de la serie tras redimensionar una de sus ocurrencias con
     * alcance `all`: todas pasan a durar lo que dura la que se redimensionó. Si se
     * arrastró el borde del INICIO, este se desplaza `deltaSeconds` y el fin de cada
     * ocurrencia se queda donde estaba; si fue el del fin, el inicio no cambia.
     *
     * No es `shift()`: aquel mueve las dos fechas a la vez, y redimensionar mueve una.
     *
     * @return array{start: DateTimeInterface|string, end: DateTimeInterface|string}
     */
    public static function resize(CalendarEvent $series, EventMutation $mutation): array
    {
        [, $allDay, $zone] = self::shape($series);

        $start = $mutation->startMarker->equals($mutation->previousStartMarker)
            ? $series->start
            : self::shifted($series->start, $mutation->deltaSeconds);

        if ($allDay || $zone === null) {
            // En pared: la duración es la de la rejilla.
            $wall = $mutation->endMarker->ts - $mutation->startMarker->ts;
            $from = self::marker($start);
            $end = $allDay ? $from->addSeconds($wall)->toDateString() : $from->addSeconds($wall)->toDateTimeString();
        } else {
            // Con zona: en tiempo real, para que una hora que cruza el cambio de horario siga siendo una hora.
            $seconds = $mutation->endInstant->getTimestamp() - $mutation->start->getTimestamp();
            $end = CarbonImmutable::instance(self::instantOf($start, $zone))->addSeconds($seconds)->setTimezone($zone);
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * La ocurrencia modificada, lista para guardar como un evento nuevo: la serie a
     * la que sustituye (`recurrenceOf`), qué ocurrencia (`recurrenceId`, en la
     * representación de la serie) y sus datos nuevos. Tiene las claves de
     * `CalendarEvent::fromArray()` salvo `id`, que es del consumidor.
     *
     * `$change` es la `EventMutation` de un movimiento o redimensión, o los datos del
     * formulario que llegan a `updateEvent()`; lo que no traiga se toma de la
     * ocurrencia tal como está. Si la ocurrencia ya era una excepción, el consumidor
     * actualiza su fila (la busca por `recurrenceOf` y `recurrenceId`) en vez de crear otra.
     *
     * No incluye los atributos que el modelo tenga fuera de `CalendarEvent` (`url`,
     * `groupId`, `meta`…): se copian de la serie.
     *
     * @param  EventInstance|EventMutation|array<string, mixed>  $occurrence  La instancia, la mutación o el `$data` de un hook.
     * @param  EventMutation|array<string, mixed>  $change
     * @return array{recurrenceOf: string|int, recurrenceId: DateTimeInterface|string, title: string, start: DateTimeInterface|string, end: DateTimeInterface|string|null, allDay: bool, resourceIds: list<string|int>, color: ?string}
     */
    public static function override(CalendarEvent $series, EventInstance|EventMutation|array $occurrence, EventMutation|array $change): array
    {
        self::ruleOf($series);
        [$recurrenceId, $occurrenceStart] = self::identify($series, $occurrence);
        [, $seriesAllDay, $zone] = self::shape($series);

        $current = self::current($occurrence);
        $base = is_array($occurrence)
            ? ['title' => $occurrence['title'] ?? $series->title, 'color' => $occurrence['color'] ?? $series->color, 'resourceIds' => $occurrence['resourceIds'] ?? $series->resourceIds]
            : ['title' => $occurrence->event->title, 'color' => $occurrence->event->color, 'resourceIds' => $occurrence->event->resourceIds];

        if ($change instanceof EventMutation) {
            $title = $change->event->title;
            $color = $change->event->color;
            $resourceIds = $change->resourceIds;
            $to = [
                'allDay' => $change->allDay,
                'start' => $change->startMarker,
                'end' => $change->end === null ? null : $change->endMarker,
                'startAt' => $change->start,
                'endAt' => $change->end,
            ];
        } else {
            $title = is_string($change['title'] ?? null) ? $change['title'] : $base['title'];
            $color = array_key_exists('color', $change) ? $change['color'] : $base['color'];
            $resourceIds = $change['resourceIds'] ?? $base['resourceIds'];
            $to = [
                'allDay' => (bool) ($change['allDay'] ?? $current['allDay']),
                'start' => is_string($change['start'] ?? null) ? Marker::parse($change['start']) : $current['start'],
                'end' => array_key_exists('end', $change) ? (is_string($change['end']) ? Marker::parse($change['end']) : null) : $current['end'],
                // Sin ellos (un array escrito a mano) se cae a la hora de pared; con el formulario siempre vienen.
                'startAt' => $change['startAt'] ?? null,
                'endAt' => $change['endAt'] ?? null,
            ];

            if (! array_key_exists('start', $change)) {
                $to['startAt'] = $current['startAt'];
            }

            if (! array_key_exists('end', $change)) {
                $to['endAt'] = $current['endAt'];
            }
        }

        return [
            'recurrenceOf' => $series->id,
            'recurrenceId' => self::represent($seriesAllDay, $recurrenceId, $occurrenceStart, $zone),
            'title' => $title,
            'start' => self::time($to['allDay'], $to['start'], $to['startAt'], $zone),
            'end' => $to['end'] === null ? null : self::time($to['allDay'], $to['end'], $to['endAt'], $zone),
            'allDay' => $to['allDay'],
            'resourceIds' => array_values($resourceIds),
            'color' => $color,
        ];
    }

    // ── Piezas ──────────────────────────────────────────────────────────

    /**
     * Qué ocurrencia es: su inicio original en hora de pared y como instante. Y que
     * es de ESTA serie: pasarle la ocurrencia de otra daría un EXDATE o un corte que
     * no corresponde a nada y se guardaría sin queja.
     *
     * @param  EventInstance|EventMutation|array<string, mixed>  $occurrence
     * @return array{0: Marker, 1: CarbonImmutable}
     */
    private static function identify(CalendarEvent $series, EventInstance|EventMutation|array $occurrence): array
    {
        [$seriesId, $recurrenceId, $occurrenceStart] = is_array($occurrence)
            ? [$occurrence['seriesId'] ?? null, $occurrence['recurrenceId'] ?? null, $occurrence['occurrenceStart'] ?? null]
            : [$occurrence->seriesId, $occurrence->recurrenceId, $occurrence->occurrenceStart];

        if ($seriesId === null || ! $recurrenceId instanceof Marker || ! $occurrenceStart instanceof CarbonImmutable) {
            throw new InvalidArgumentException("Evento «{$series->id}»: lo que se pasó no es una ocurrencia de una serie.");
        }

        if ((string) $seriesId !== (string) $series->id) {
            throw new InvalidArgumentException("La ocurrencia es de la serie «{$seriesId}», no de «{$series->id}».");
        }

        return [$recurrenceId, $occurrenceStart];
    }

    /**
     * La primera ocurrencia normalizada, si es de día completo y la zona de la regla
     * (`null` = flotante). Lo mismo que calcula la expansión, con un reloj en UTC
     * porque ninguno de los tres depende de la zona de la vista: una regla flotante
     * está en pared, una con zona en su zona, y un día completo no tiene zona.
     *
     * @return array{0: EventInstance, 1: bool, 2: ?DateTimeZone}
     */
    private static function shape(CalendarEvent $series): array
    {
        $first = (new EventNormalizer(new WallClock('UTC')))->normalize($series->occurrence($series->start, $series->end));

        return [$first, $first->allDay, RecurrenceExpander::zoneOf($series, $first->allDay)];
    }

    /**
     * La ocurrencia en la representación de la serie: su día si es de día completo
     * (que excluye la ocurrencia de ese día, sea a la hora que sea), su hora de pared
     * si es flotante o su instante en la zona de la serie si la tiene.
     */
    private static function represent(bool $allDay, Marker $recurrenceId, CarbonImmutable $occurrenceStart, ?DateTimeZone $zone): string|DateTimeInterface
    {
        return match (true) {
            $allDay => $recurrenceId->toDateString(),
            $zone === null => $recurrenceId->toDateTimeString(),
            default => $occurrenceStart->setTimezone($zone),
        };
    }

    private static function ruleOf(CalendarEvent $series): string
    {
        if ($series->rrule === null) {
            throw new InvalidArgumentException("Evento «{$series->id}»: no es una serie (`rrule` es null).");
        }

        return trim($series->rrule, " \t\n\r\0\x0B;");
    }

    /** @return list<string> */
    private static function parts(string $rule): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $rule)), fn (string $p) => $p !== ''));
    }

    private static function isPart(string $part, string $name): bool
    {
        return strcasecmp(strstr($part, '=', true) ?: $part, $name) === 0;
    }

    private static function hasPart(string $rule, string $name): bool
    {
        foreach (self::parts($rule) as $part) {
            if (self::isPart($part, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function withoutParts(string $rule, array $names): array
    {
        return array_values(array_filter(
            self::parts($rule),
            fn (string $part) => ! array_filter($names, fn (string $name) => self::isPart($part, $name)),
        ));
    }

    /** La regla tal como la expande `RecurrenceExpander`: mismo inicio, mismo `UNTIL` normalizado. */
    private static function rrule(CalendarEvent $series, EventInstance $first, ?DateTimeZone $zone): RRule
    {
        return new RRule(
            RecurrenceExpander::normalizeUntil(self::ruleOf($series), $zone),
            RecurrenceExpander::dtstart($first, $zone),
        );
    }

    /**
     * El instante de la ocurrencia para comparar con lo que devuelve php-rrule: la
     * hora de pared como si fuera UTC en una regla flotante, el instante real en una con zona.
     */
    private static function instant(Marker $recurrenceId, CarbonImmutable $occurrenceStart, ?DateTimeZone $zone): DateTimeImmutable
    {
        return $zone === null ? new DateTimeImmutable('@'.$recurrenceId->ts) : DateTimeImmutable::createFromInterface($occurrenceStart);
    }

    // ── Fechas de la serie ──────────────────────────────────────────────

    /**
     * Una fecha de la serie desplazada. Cada forma sigue siendo la suya: un día
     * completo sigue siendo 'Y-m-d', una hora flotante 'Y-m-d H:i:s', y una fecha con
     * zona (o un string con offset) la conserva.
     */
    private static function shifted(DateTimeInterface|string $value, int $delta): DateTimeInterface|string
    {
        if ($value instanceof DateTimeInterface) {
            return self::shiftedIn($value, $delta);
        }

        $text = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return Marker::parse($text)->addSeconds($delta)->toDateString();
        }

        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $text)) {
            return self::shiftedIn(new DateTimeImmutable($text), $delta)->format('Y-m-d\TH:i:sP');
        }

        return Marker::parse(str_replace('T', ' ', substr($text, 0, 19)))->addSeconds($delta)->toDateTimeString();
    }

    /** Suma en pared, en la zona de la propia fecha (y no en segundos absolutos, que cambian con el horario). */
    private static function shiftedIn(DateTimeInterface $date, int $delta): CarbonImmutable
    {
        return CarbonImmutable::parse(Marker::fromWallFields($date)->addSeconds($delta)->toDateTimeString(), $date->getTimezone());
    }

    /** Una fecha de la serie como hora de pared, sin tocar la zona. */
    private static function marker(DateTimeInterface|string $value): Marker
    {
        if ($value instanceof DateTimeInterface) {
            return Marker::fromWallFields($value);
        }

        return Marker::parse(str_replace('T', ' ', substr(trim($value), 0, 19)));
    }

    private static function instantOf(DateTimeInterface|string $value, DateTimeZone $zone): DateTimeInterface
    {
        return $value instanceof DateTimeInterface ? $value : new DateTimeImmutable($value, $zone);
    }

    /** Una hora del cambio, en la representación de la serie. */
    private static function time(bool $allDay, Marker $marker, ?CarbonImmutable $instant, ?DateTimeZone $zone): string|DateTimeInterface
    {
        if ($allDay) {
            return $marker->toDateString();
        }

        if ($zone === null) {
            return $marker->toDateTimeString();
        }

        return $instant?->setTimezone($zone) ?? CarbonImmutable::parse($marker->toDateTimeString(), $zone);
    }

    /**
     * Cómo está ahora la ocurrencia, para lo que un cambio parcial no traiga. De los
     * datos de un hook (`$data`) solo se sabe cómo quedó: es lo único que traen.
     *
     * @param  EventInstance|EventMutation|array<string, mixed>  $occurrence
     * @return array{allDay: bool, start: Marker, end: ?Marker, startAt: ?CarbonImmutable, endAt: ?CarbonImmutable}
     */
    private static function current(EventInstance|EventMutation|array $occurrence): array
    {
        if (is_array($occurrence)) {
            $marker = fn (mixed $value) => is_string($value) ? Marker::parse($value) : null;

            return [
                'allDay' => (bool) ($occurrence['allDay'] ?? false),
                'start' => $marker($occurrence['start'] ?? null) ?? $occurrence['recurrenceId'] ?? Marker::fromTimestamp(0),
                'end' => $marker($occurrence['end'] ?? null),
                'startAt' => $occurrence['startAt'] ?? null,
                'endAt' => $occurrence['endAt'] ?? null,
            ];
        }

        if ($occurrence instanceof EventInstance) {
            return [
                'allDay' => $occurrence->allDay,
                'start' => $occurrence->range->start,
                'end' => $occurrence->hasEnd ? $occurrence->range->end : null,
                'startAt' => $occurrence->exactStart,
                'endAt' => $occurrence->hasEnd ? $occurrence->exactEnd : null,
            ];
        }

        return [
            'allDay' => $occurrence->previousAllDay,
            'start' => $occurrence->previousStartMarker,
            'end' => $occurrence->previousEnd === null ? null : $occurrence->previousEndMarker,
            'startAt' => $occurrence->previousStart,
            'endAt' => $occurrence->previousEnd,
        ];
    }
}
