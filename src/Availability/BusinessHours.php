<?php

namespace KoreUi\Calendar\Availability;

use InvalidArgumentException;
use KoreUi\Calendar\Resources\CalendarResource;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;

/**
 * El horario laboral: una lista de tramos `{daysOfWeek, startTime, endTime}`, la
 * misma forma que `businessHours` de FullCalendar (structs/business-hours.ts).
 *
 *     new BusinessHours(true);                                              // lun-vie 09:00-17:00
 *     new BusinessHours(['daysOfWeek' => [1, 2, 3], 'startTime' => '08:00', 'endTime' => '14:00']);
 *     new BusinessHours([                                                   // varios tramos
 *         ['daysOfWeek' => [1, 2, 3, 4, 5], 'startTime' => '09:00', 'endTime' => '13:00'],
 *         ['daysOfWeek' => [1, 2, 3, 4, 5], 'startTime' => '15:00', 'endTime' => '19:00'],
 *     ]);
 *     new BusinessHours(['daysOfWeek' => [5], 'startTime' => '22:00', 'endTime' => '30:00']);   // turno de noche
 *
 * **Sin tramos (`false`, `null`, `[]`) no hay horario definido, no «cerrado»**: nada
 * se pinta y todo vale, como si siempre estuviera abierto. FullCalendar hace lo
 * contrario con `eventConstraint: 'businessHours'` y `businessHours: false`
 * (rechaza todo), y es una trampa: quien no define horario no quiere prohibir nada.
 *
 * Todo es **hora de pared** (`Marker`), la de la vista: las 09:00 son las 09:00
 * también el día del cambio de horario, igual que en la rejilla. El horario no se
 * mueve con los cambios de hora, que es lo que una oficina espera.
 *
 * `endTime` puede pasar de 24:00 (hasta 48:00): el tramo cruza la medianoche y
 * cuenta para el día siguiente. Los tramos contiguos o solapados se funden, así
 * que 09:00-13:00 y 13:00-17:00 son un solo tramo de 09:00 a 17:00.
 *
 * Inmutable. Un tramo mal escrito lanza `InvalidArgumentException` al construir:
 * un horario que se ignora en silencio es peor que uno que falla.
 */
final class BusinessHours
{
    /** Las claves de un tramo. Sin las de pintado de FullCalendar (`display`, `className`, `groupId`): aquí no se pinta como evento. */
    public const KEYS = ['daysOfWeek', 'startTime', 'endTime'];

    private const DEFAULT_DAYS = [1, 2, 3, 4, 5];

    private const DEFAULT_START = '09:00';

    private const DEFAULT_END = '17:00';

    /** El fin de un tramo llega hasta las 48:00: empieza un día y acaba, como mucho, al acabar el siguiente. */
    private const MAX_END = 2 * Marker::DAY;

    /**
     * Un rango más largo que esto no se comprueba (no está cubierto). Ningún
     * evento dura diez años, y sin tope un `resizeEvent` del navegador con un
     * extremo en el año 1 haría recorrer tres millones de días.
     */
    private const MAX_SPAN_DAYS = 3660;

    /** @var list<array{days: list<int>, start: int, end: int}> Segundos desde las 00:00 del día del tramo. */
    private readonly array $blocks;

    /**
     * @param  self|bool|array<int|string, mixed>|null  $hours  `true` = lun-vie 09:00-17:00; `false`, `null` o `[]` = sin horario;
     *                                                          un tramo (array asociativo) o una lista de tramos.
     */
    public function __construct(self|bool|array|null $hours = false)
    {
        $this->blocks = match (true) {
            $hours instanceof self => $hours->blocks,
            $hours === true => [self::parseBlock([], 'Horario laboral', false)],
            $hours === false, $hours === null, $hours === [] => [],
            array_is_list($hours) => self::parseList($hours),
            default => [self::parseBlock($hours, 'Horario laboral', false)],
        };
    }

    /** Como el constructor, pero para un valor que viene de fuera (config, props, un recurso): si no es de ningún tipo válido, falla en español. */
    public static function from(mixed $hours): self
    {
        if (! $hours instanceof self && ! is_bool($hours) && ! is_array($hours) && $hours !== null) {
            throw new InvalidArgumentException('Horario laboral: se esperaba true, false, null, un tramo o una lista de tramos, y llegó '.get_debug_type($hours).'.');
        }

        return $hours instanceof self ? $hours : new self($hours);
    }

    /**
     * El horario que manda en un recurso: el suyo si lo tiene, si no el del calendario.
     * Un recurso con `businessHours: false` (o `[]`) está siempre abierto aunque el
     * calendario tenga horario: una máquina que trabaja de noche.
     */
    public static function forResource(?CalendarResource $resource, self $calendar): self
    {
        if ($resource === null || $resource->businessHours === null) {
            return $calendar;
        }

        try {
            return self::from($resource->businessHours);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Recurso «{$resource->id}»: {$e->getMessage()}", 0, $e);
        }
    }

    /** No hay ningún tramo: no hay horario definido y todo vale. */
    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    /**
     * Los tramos tal como se escribirían: `HH:MM` y días ordenados.
     *
     * @return list<array{daysOfWeek: list<int>, startTime: string, endTime: string}>
     */
    public function toArray(): array
    {
        return array_map(fn (array $b) => [
            'daysOfWeek' => $b['days'],
            'startTime' => self::format($b['start']),
            'endTime' => self::format($b['end']),
        ], $this->blocks);
    }

    /**
     * Los tramos laborables que caen dentro del rango, recortados a él, ordenados y
     * ya fundidos (los contiguos son uno). Sin horario definido, el rango entero.
     *
     * Se recorre desde el día ANTERIOR al inicio: un turno de noche que empezó
     * ayer sigue abierto esta madrugada.
     *
     * @return list<DateRange>
     */
    public function intervals(DateRange $range): array
    {
        if ($range->seconds() <= 0) {
            return [];
        }

        if ($this->blocks === []) {
            return [$range];
        }

        $from = $range->start->ts;
        $to = $range->end->ts;
        $day = $range->start->startOfDay()->addDays(-1);
        $dow = $day->dayOfWeek();
        $spans = [];

        for (; $day->ts < $to; $day = $day->addDays(1), $dow = ($dow + 1) % 7) {
            foreach ($this->blocks as $block) {
                if (! in_array($dow, $block['days'], true)) {
                    continue;
                }

                $start = max($from, $day->ts + $block['start']);
                $end = min($to, $day->ts + $block['end']);

                if ($start < $end) {
                    $spans[] = [$start, $end];
                }
            }
        }

        usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($spans as [$start, $end]) {
            $last = array_key_last($merged);

            // `<=`: dos tramos que solo se tocan en el borde (13:00 y 13:00) son uno.
            if ($last !== null && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return array_map(fn (array $p) => new DateRange(Marker::fromTimestamp($p[0]), Marker::fromTimestamp($p[1])), $merged);
    }

    /**
     * Lo que NO es laborable dentro del rango: el complemento de `intervals()`. Es
     * lo que se pinta con `--kore-calendar-non-business`. Sin horario definido, nada.
     *
     * @return list<DateRange>
     */
    public function outside(DateRange $range): array
    {
        return self::complement($range, $this->intervals($range));
    }

    /**
     * Lo que queda del rango tras quitarle unos intervalos ordenados y disjuntos.
     * Público porque el layout ya tiene los intervalos (los necesita también para
     * el cliente) y no hace falta calcularlos dos veces.
     *
     * @param  list<DateRange>  $intervals
     * @return list<DateRange>
     */
    public static function complement(DateRange $range, array $intervals): array
    {
        $gaps = [];
        $cursor = $range->start->ts;

        foreach ($intervals as $interval) {
            if ($interval->start->ts > $cursor) {
                $gaps[] = new DateRange(Marker::fromTimestamp($cursor), $interval->start);
            }

            $cursor = max($cursor, $interval->end->ts);
        }

        if ($cursor < $range->end->ts) {
            $gaps[] = new DateRange(Marker::fromTimestamp($cursor), $range->end);
        }

        return $gaps;
    }

    /**
     * El tramo cabe ENTERO dentro de la unión de intervalos contiguos. Es
     * semiabierto como todo el paquete: [09:00, 17:00) cubre [09:00, 17:00) y
     * [16:59, 17:00), pero no [16:59, 17:01). Un tramo vacío no está cubierto por
     * nada (no hay qué cubrir); sin horario definido, todo lo está.
     */
    public function covers(DateRange $span): bool
    {
        if ($this->blocks === []) {
            return true;
        }

        if ($span->seconds() <= 0 || $span->seconds() > self::MAX_SPAN_DAYS * Marker::DAY) {
            return false;
        }

        $inside = $this->intervals($span);

        return count($inside) === 1 && $inside[0]->equals($span);
    }

    /**
     * Cada día del rango tiene algún tramo laborable. Es la regla de un evento de
     * DÍA COMPLETO, que no tiene hora y por eso no puede «caber» en 09:00-17:00:
     * lo que se pregunta es si se pone en un día en que se trabaja.
     */
    public function coversDays(DateRange $span): bool
    {
        if ($this->blocks === []) {
            return true;
        }

        if ($span->seconds() <= 0 || $span->seconds() > self::MAX_SPAN_DAYS * Marker::DAY) {
            return false;
        }

        foreach ($span->eachDay() as $day) {
            if (! $this->worksOn($day)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hay algún tramo laborable ese día (también uno que viene del día anterior:
     * el martes de madrugada de un turno que empezó el lunes). Es lo que atenúa
     * un día en la vista mes.
     */
    public function worksOn(Marker $day): bool
    {
        return $this->blocks === [] || $this->intervals(DateRange::days($day->startOfDay(), 1)) !== [];
    }

    /**
     * Los intervalos como los lee el cliente (`data-business`): `inicio:fin` en
     * markers, separados por comas. Vacío = no se trabaja en ese destino. Solo
     * es una pista para marcar la previsualización: decide el servidor.
     *
     * @param  list<DateRange>  $intervals
     */
    public static function encode(array $intervals): string
    {
        return implode(',', array_map(fn (DateRange $i) => $i->start->ts.':'.$i->end->ts, $intervals));
    }

    // ── Lectura de la entrada ───────────────────────────────────────────

    /**
     * @param  array<int, mixed>  $list
     * @return list<array{days: list<int>, start: int, end: int}>
     */
    private static function parseList(array $list): array
    {
        $blocks = [];

        foreach ($list as $index => $raw) {
            $where = 'Horario laboral, tramo '.($index + 1);

            if (! is_array($raw)) {
                throw new InvalidArgumentException("{$where}: se esperaba un array con daysOfWeek, startTime y endTime, y llegó ".get_debug_type($raw).'.');
            }

            $blocks[] = self::parseBlock($raw, $where, true);
        }

        return $blocks;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     * @param  bool  $requireDays  En una lista cada tramo dice sus días (FullCalendar descarta en silencio el que no los dice; aquí lanza).
     * @return array{days: list<int>, start: int, end: int}
     */
    private static function parseBlock(array $raw, string $where, bool $requireDays): array
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($raw)), self::KEYS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s: clave%s desconocida%s %s. Permitidas: %s.',
                $where,
                count($unknown) > 1 ? 's' : '',
                count($unknown) > 1 ? 's' : '',
                implode(', ', array_map(fn (string $k) => "«{$k}»", $unknown)),
                implode(', ', self::KEYS),
            ));
        }

        if ($requireDays && ! array_key_exists('daysOfWeek', $raw)) {
            throw new InvalidArgumentException("{$where}: falta `daysOfWeek`. En una lista de tramos cada uno dice sus días; lunes a viernes es [1, 2, 3, 4, 5].");
        }

        $days = self::parseDays($raw['daysOfWeek'] ?? self::DEFAULT_DAYS, $where);
        $start = self::parseTime($raw['startTime'] ?? self::DEFAULT_START, 'startTime', $where, Marker::DAY - 1);
        $end = self::parseTime($raw['endTime'] ?? self::DEFAULT_END, 'endTime', $where, self::MAX_END);

        if ($end <= $start) {
            throw new InvalidArgumentException(sprintf(
                '%s: endTime (%s) tiene que ser posterior a startTime (%s). Un turno que cruza la medianoche acaba pasadas las 24:00, por ejemplo 26:00.',
                $where, self::format($end), self::format($start),
            ));
        }

        return ['days' => $days, 'start' => $start, 'end' => $end];
    }

    /** @return list<int> */
    private static function parseDays(mixed $days, string $where): array
    {
        if (! is_array($days) || $days === []) {
            throw new InvalidArgumentException("{$where}: `daysOfWeek` tiene que ser una lista no vacía de días (0 = domingo … 6 = sábado).");
        }

        foreach ($days as $day) {
            if (! is_int($day) || $day < 0 || $day > 6) {
                $shown = is_scalar($day) ? "«{$day}»" : get_debug_type($day);

                throw new InvalidArgumentException("{$where}: el día {$shown} de `daysOfWeek` no es válido. Se esperan enteros de 0 (domingo) a 6 (sábado).");
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    private static function parseTime(mixed $value, string $key, string $where, int $max): int
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2}):([0-5]\d)$/', trim($value), $m)) {
            $shown = is_string($value) ? "«{$value}»" : get_debug_type($value);

            throw new InvalidArgumentException("{$where}: `{$key}` no es una hora válida (llegó {$shown}). Se espera HH:MM, por ejemplo 09:00 o 26:00.");
        }

        $seconds = (int) $m[1] * 3600 + (int) $m[2] * 60;

        if ($seconds > $max) {
            $limit = $key === 'startTime' ? 'menor que 24:00' : 'como mucho 48:00';

            throw new InvalidArgumentException("{$where}: `{$key}` ({$value}) tiene que ser {$limit}.");
        }

        return $seconds;
    }

    private static function format(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
