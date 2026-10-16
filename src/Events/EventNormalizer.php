<?php

namespace KoreUi\Calendar\Events;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

/**
 * De `CalendarEvent` (lo que dijo el consumidor) a `EventInstance` (lo que la
 * rejilla puede pintar). Aquí se aplican, en un solo sitio, las reglas del
 * modelo de tiempo (docs/calendar.md §5):
 *
 * - **Día completo:** sus fechas son de calendario, no instantes. No se
 *   convierten de zona: el 16 es el 16 en Tokio y en Los Ángeles. Fin exclusivo
 *   (3-sep → 5-sep cubre el 3 y el 4).
 * - **Con hora:** se convierte una sola vez, al entrar, a hora de pared en la
 *   zona de la vista. Si llegó con instante se conserva junto al marker.
 * - **Fin ausente o inválido** (`fin <= inicio`): el evento no se descarta
 *   (FullCalendar 2.2.3 lo hacía, y el evento desaparecía sin explicación); se
 *   le da la duración por defecto y `hasEnd` queda en false.
 */
final class EventNormalizer
{
    /**
     * Lo único que se acepta como fecha en un string. Más estricto que
     * `new DateTimeImmutable()` a propósito: aquel acepta "next monday" o
     * "tomorrow", y un campo así en una fila de la base de datos es una errata,
     * no una fecha.
     */
    private const ISO = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/i';

    /** El mismo criterio que `WallClock::toMarker()` para decidir si un string trae instante. */
    private const OFFSET = '/(Z|[+-]\d{2}:?\d{2})$/i';

    public function __construct(
        private readonly WallClock $clock,
        private readonly int $defaultTimedSeconds = 3600,
        private readonly int $defaultAllDayDays = 1,
    ) {
        if ($defaultTimedSeconds < 1) {
            throw new InvalidArgumentException("La duración por defecto de un evento con hora debe ser de al menos 1 segundo (llegó {$defaultTimedSeconds}).");
        }

        if ($defaultAllDayDays < 1) {
            throw new InvalidArgumentException("La duración por defecto de un evento de día completo debe ser de al menos 1 día (llegó {$defaultAllDayDays}).");
        }
    }

    public function normalize(CalendarEvent $event): EventInstance
    {
        $allDay = $event->allDay ?? self::inferAllDay($event);

        return $allDay ? $this->allDay($event) : $this->timed($event);
    }

    /**
     * Sin hora en el inicio (y en el fin, si lo hay). Un `DateTimeInterface`
     * nunca se infiere: es un instante, y un instante tiene hora aunque sea
     * medianoche.
     */
    private static function inferAllDay(CalendarEvent $event): bool
    {
        return self::isDateOnly($event->start)
            && ($event->end === null || self::isDateOnly($event->end));
    }

    private static function isDateOnly(DateTimeInterface|string $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1;
    }

    private function allDay(CalendarEvent $event): EventInstance
    {
        $start = $this->calendarDay($event, 'start', $event->start);
        $end = $event->end === null ? null : $this->calendarDay($event, 'end', $event->end);

        // Tras truncar, un fin en el mismo día que el inicio también es inválido:
        // el rango sería vacío.
        $hasEnd = $end !== null && $end->ts > $start->ts;

        if (! $hasEnd) {
            $end = $start->addDays($this->defaultAllDayDays);
        }

        return new EventInstance($event, new DateRange($start, $end), true, $hasEnd);
    }

    /** La fecha de calendario tal como está escrita, a las 00:00 y sin tocar la zona. */
    private function calendarDay(CalendarEvent $event, string $field, DateTimeInterface|string $value): Marker
    {
        if ($value instanceof DateTimeInterface) {
            return Marker::fromWallFields($value)->startOfDay();
        }

        $this->assertIso($event, $field, $value);

        try {
            return Marker::parse($value)->startOfDay();
        } catch (InvalidArgumentException) {
            // Con hora, offset o 'Z': es lo que serializa Eloquent para una columna
            // `date` ('2026-10-16T00:00:00.000000Z'). Los campos se leen como
            // vienen. Convertirlos a la zona de la vista movería el día.
            try {
                return Marker::fromWallFields(new DateTimeImmutable(trim($value)))->startOfDay();
            } catch (Exception $e) {
                throw $this->invalid($event, $field, $value, $e);
            }
        }
    }

    private function timed(CalendarEvent $event): EventInstance
    {
        [$start, $exactStart] = $this->resolve($event, 'start', $event->start);

        $end = $exactEnd = null;

        if ($event->end !== null) {
            [$end, $exactEnd] = $this->resolve($event, 'end', $event->end);
        }

        // Con los dos instantes, se comparan instantes: en el pliegue de otoño
        // 02:30+01:00 va DESPUÉS de 02:30+02:00 aunque sus markers sean iguales.
        $valid = $end !== null && ($exactStart !== null && $exactEnd !== null
            ? $exactEnd > $exactStart
            : $end->ts > $start->ts);

        if ($valid) {
            // Fin válido como instante pero no como marker: pasa en el pliegue
            // (02:30+02:00 → 02:30+01:00, o el fin más pequeño en pared). El rango
            // no puede ser vacío ni invertido, así que se re-expresa como inicio +
            // duración real. Mínimo 1 s por si ambos caen en el mismo segundo.
            if ($end->ts <= $start->ts) {
                $end = $start->addSeconds(max(1, $exactEnd->getTimestamp() - $exactStart->getTimestamp()));
            }

            return new EventInstance($event, new DateRange($start, $end), false, true, $exactStart, $exactEnd);
        }

        // Sin fin válido. Con instante de inicio, la duración corre en tiempo real
        // (una hora tras 01:30 la noche del cambio son las 03:30 en Madrid, no las
        // 02:30, que no existe); sin él solo hay hora de pared y se suma ahí.
        if ($exactStart !== null) {
            $exactEnd = $exactStart->addSeconds($this->defaultTimedSeconds);
            $end = $this->clock->toMarker($exactEnd);

            // Pliegue: 02:30+02:00 + 1 h = 02:30+01:00, el mismo marker que el inicio.
            if ($end->ts <= $start->ts) {
                $end = $start->addSeconds($this->defaultTimedSeconds);
            }
        } else {
            $exactEnd = null;
            $end = $start->addSeconds($this->defaultTimedSeconds);
        }

        return new EventInstance($event, new DateRange($start, $end), false, false, $exactStart, $exactEnd);
    }

    /**
     * Un valor de fecha → su marker y, si traía instante, el instante en la zona
     * de la vista. Un string sin offset es hora de pared "flotante": no tiene
     * instante hasta que alguien decida en qué zona se lee.
     *
     * @return array{0: Marker, 1: ?CarbonImmutable}
     */
    private function resolve(CalendarEvent $event, string $field, DateTimeInterface|string $value): array
    {
        $exact = null;

        if ($value instanceof DateTimeInterface) {
            $exact = CarbonImmutable::instance($value)->setTimezone($this->clock->zone);
        } else {
            $this->assertIso($event, $field, $value);

            if (preg_match(self::OFFSET, trim($value)) === 1) {
                try {
                    $exact = CarbonImmutable::parse($value)->setTimezone($this->clock->zone);
                } catch (Exception $e) {
                    throw $this->invalid($event, $field, $value, $e);
                }
            }
        }

        return [$this->clock->toMarker($exact ?? $value), $exact];
    }

    private function assertIso(CalendarEvent $event, string $field, string $value): void
    {
        $ok = preg_match(self::ISO, trim($value), $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) ($m[4] ?? 0) < 24
            && (int) ($m[5] ?? 0) < 60
            && (int) ($m[6] ?? 0) < 60;

        // `Marker::of()` pasa por gmmktime, que normaliza en silencio: sin esta
        // comprobación '2026-02-30' sería el 2 de marzo y nadie se enteraría.
        if (! $ok) {
            throw $this->invalid($event, $field, $value);
        }
    }

    private function invalid(CalendarEvent $event, string $field, string $value, ?Exception $previous = null): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Evento «{$event->id}»: `{$field}` «{$value}» no es una fecha válida. Se espera ISO 8601: YYYY-MM-DD, YYYY-MM-DD HH:MM[:SS] o con offset/Z.",
            0,
            $previous,
        );
    }
}
