<?php

namespace KoreUi\Calendar\View;

use DateTimeInterface;
use InvalidArgumentException;
use KoreUi\Calendar\Availability\BusinessHours;
use KoreUi\Calendar\Interaction\Bounds;
use KoreUi\Calendar\Layout\EventOrder;
use KoreUi\Calendar\Layout\LayoutOptions;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewOptions;
use KoreUi\Calendar\Time\WallClock;
use KoreUi\Calendar\Time\WeekRules;
use KoreUi\Charts\Duration;

/**
 * La configuración resuelta de UN calendario: config del paquete + lo que diga la
 * etiqueta o `configure()`. Prop > config > default, la misma cascada que `Look`
 * en kore-ui. Todo lo que el motor necesita sale de aquí ya convertido a sus tipos
 * (segundos, `WeekRules`, `Marker`), así que ninguna vista lee config a pelo.
 */
final class CalendarSettings
{
    private function __construct(
        public readonly string $view,
        public readonly Marker $date,
        public readonly WallClock $clock,
        public readonly string $locale,
        public readonly WeekRules $weekRules,
        public readonly ?WeekRules $weekNumbers,
        public readonly ViewOptions $viewOptions,
        public readonly LayoutOptions $layoutOptions,
        public readonly int $slotDuration,
        public readonly bool $nowIndicator,
        public readonly bool $datesAboveResources,
        public readonly int $defaultTimedSeconds,
        public readonly int $defaultAllDayDays,
        /** @var array<string, string> */
        public readonly array $formats,
        public readonly int $timelineSlot = 3600,
        public readonly ?int $timelineRowStart = null,
        public readonly ?int $timelineRowLimit = null,
        public readonly int $timelineScrollTime = 8 * 3600,
        // ── Interacción (docs/calendar.md §10-11). Apagada por defecto. ──
        public readonly bool $editable = false,
        public readonly bool $selectable = false,
        /** Paso del gesto, en segundos. Nunca mayor que el slot ni un no divisor suyo. */
        public readonly int $snapSeconds = 1800,
        public readonly bool $eventOverlap = true,
        public readonly bool $allDayMaintainDuration = false,
        public readonly bool $resourceEditable = true,
        /** Lo editable, en markers y con fin exclusivo (el día siguiente al `end` del config). `null` = sin límites. */
        public readonly ?DateRange $validRange = null,
        /** Los eventos se pueden pulsar (llega a `onEventClick()`): se pintan enfocables aunque el calendario no sea editable. */
        public readonly bool $eventClick = false,
        // ── Horario laboral y conflictos (docs/calendar.md §7.5, §11). ──
        /** El del calendario; el de un recurso (`CalendarResource::businessHours`) gana para sus columnas o filas. */
        public readonly BusinessHours $businessHours = new BusinessHours,
        /** `'businessHours'`: un evento movido o redimensionado tiene que quedar en horario laboral. `null` = sin regla. */
        public readonly ?string $eventConstraint = null,
        /** Lo mismo para seleccionar un rango. */
        public readonly ?string $selectConstraint = null,
        /** `false`: no se puede seleccionar un rango que pisa un evento. */
        public readonly bool $selectOverlap = true,
        /** Marcar los eventos que se solapan en el mismo recurso. */
        public readonly bool $highlightConflicts = false,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  Claves con la misma forma que el config (con puntos: 'time.slot_duration').
     */
    public static function make(array $overrides = []): self
    {
        $get = function (string $key, mixed $default = null) use ($overrides) {
            return array_key_exists($key, $overrides) && $overrides[$key] !== null
                ? $overrides[$key]
                : config("kore-calendar.{$key}", $default);
        };

        $locale = (string) ($get('locale') ?? app()->getLocale());
        $clock = new WallClock($get('timezone') ?? config('app.timezone', 'UTC'));
        $firstDay = $get('first_day') ?? config('kore-ui.form.datepicker.start_of_week', 1);
        $numbering = $get('week_numbers', false);

        $weekRules = WeekRules::resolve((int) $firstDay, $numbering === 'locale' ? 'locale' : 'iso', $locale);
        $weekNumbers = $numbering ? $weekRules : null;

        $date = $get('date');
        $date = match (true) {
            $date === null => $clock->now(),
            $date instanceof Marker => $date,
            $date instanceof DateTimeInterface, is_string($date) => $clock->toMarker($date),
            default => throw new InvalidArgumentException('La fecha del calendario tiene que ser un string, una fecha o un Marker.'),
        };

        $slotMin = self::timeOfDay((string) $get('time.slot_min_time', '00:00'));
        $slotMax = self::timeOfDay((string) $get('time.slot_max_time', '24:00'));

        if ($slotMax <= $slotMin) {
            throw new InvalidArgumentException('slot_max_time tiene que ser posterior a slot_min_time.');
        }

        $layout = new LayoutOptions(
            order: new EventOrder((string) $get('event_order', EventOrder::DEFAULT), $locale),
            orderStrict: (bool) $get('event_order_strict', false),
            maxEvents: self::intOrNull($get('month.max_events')),
            maxEventRows: self::intOrNull($get('month.max_event_rows')),
            nextDayThreshold: self::timeOfDay((string) $get('time.next_day_threshold', '00:00')),
            slotMin: $slotMin,
            slotMax: $slotMax,
            eventMinSeconds: (int) $get('time.event_min_minutes', 15) * 60,
            eventShortSeconds: (int) $get('time.event_short_minutes', 30) * 60,
            maxStack: self::intOrNull($get('time_grid.max_stack')),
            slotEventOverlap: (bool) $get('time_grid.slot_event_overlap', true),
            allDayMaxEventRows: self::intOrNull($get('time_grid.all_day_max_rows', 5)),
        );

        $timeline = self::timeline($get, (string) $get('view', $get('default_view', 'month')));
        $slotDuration = (int) Duration::seconds($get('time.slot_duration', '30m'));

        return new self(
            view: (string) $get('view', $get('default_view', 'month')),
            date: $date,
            clock: $clock,
            locale: $locale,
            weekRules: $weekRules,
            weekNumbers: $weekNumbers,
            viewOptions: new ViewOptions(
                weekRules: $weekRules,
                hiddenDays: (array) $get('hidden_days', []),
                fixedWeekCount: (bool) $get('month.fixed_week_count', true),
                showNonCurrentDates: (bool) $get('month.show_non_current_dates', true),
            ),
            layoutOptions: $layout,
            slotDuration: $slotDuration,
            nowIndicator: (bool) $get('now_indicator', true),
            datesAboveResources: (bool) $get('time_grid.dates_above_resources', false),
            defaultTimedSeconds: (int) Duration::seconds($get('time.default_timed_duration', '1h')),
            defaultAllDayDays: (int) $get('time.default_all_day_duration', 1),
            formats: array_merge((array) config('kore-calendar.formats', []), (array) ($overrides['formats'] ?? [])),
            timelineSlot: $timeline['timelineSlot'],
            timelineRowStart: $timeline['timelineRowStart'],
            timelineRowLimit: $timeline['timelineRowLimit'],
            timelineScrollTime: self::timeOfDay((string) $get('timeline.scroll_time', '08:00')),
            editable: (bool) $get('interaction.editable', false),
            selectable: (bool) $get('interaction.selectable', false),
            snapSeconds: self::snapSeconds($get('interaction.snap'), $slotDuration),
            eventOverlap: (bool) $get('interaction.event_overlap', true),
            allDayMaintainDuration: (bool) $get('interaction.all_day_maintain_duration', false),
            resourceEditable: (bool) $get('interaction.resource_editable', true),
            validRange: self::validRange($get('interaction.valid_range')),
            eventClick: (bool) $get('interaction.event_click', false),
            businessHours: BusinessHours::from($get('business_hours', false)),
            eventConstraint: self::constraint($get('interaction.event_constraint'), 'interaction.event_constraint'),
            selectConstraint: self::constraint($get('interaction.select_constraint'), 'interaction.select_constraint'),
            selectOverlap: (bool) $get('interaction.select_overlap', true),
            highlightConflicts: (bool) $get('highlight_conflicts', false),
        );
    }

    /**
     * `'businessHours'` o nada. FullCalendar admite también un `groupId`, un rango o
     * una lista de eventos como restricción; aquí no, y una errata no se ignora en
     * silencio: una restricción que no se aplica deja pasar justo lo que debía parar.
     * `false` y `''` valen como «sin regla» para poder apagar la del config desde `configure()`.
     */
    private static function constraint(mixed $value, string $key): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if ($value === 'businessHours') {
            return $value;
        }

        throw new InvalidArgumentException(sprintf(
            '%s solo admite \'businessHours\' o null (llegó %s). La regla de negocio que no sea el horario laboral va en persistMove() / persistResize().',
            $key,
            is_string($value) ? "«{$value}»" : get_debug_type($value),
        ));
    }

    /**
     * El paso del gesto en segundos. `null` = el slot.
     *
     * Si no divide el slot (un snap de 20 min con slots de 30), se usa el slot: es
     * lo que hace el cliente (docs §10, y FullCalendar `queryHit`), y el servidor
     * tiene que ajustar a la misma rejilla que el navegador dibujó. Un snap mayor
     * que el slot tampoco lo divide, así que cae al slot por el mismo camino.
     */
    private static function snapSeconds(mixed $snap, int $slot): int
    {
        if ($snap === null || $snap === '') {
            return $slot;
        }

        $seconds = (int) Duration::seconds($snap);

        return $seconds >= 1 && $slot % $seconds === 0 ? $seconds : $slot;
    }

    /**
     * `['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD']` → rango de markers.
     *
     * El `end` del config incluye ese día (lo natural al escribirlo: «hasta el 31
     * de diciembre»); el rango es semiabierto como todo el paquete, así que acaba
     * el día siguiente. Con un solo extremo, el otro queda abierto hasta los
     * límites que el servidor acepta de todos modos.
     */
    private static function validRange(mixed $range): ?DateRange
    {
        $range = is_array($range) ? $range : [];
        $start = trim((string) ($range['start'] ?? ''));
        $end = trim((string) ($range['end'] ?? ''));

        if ($start === '' && $end === '') {
            return null;
        }

        try {
            return new DateRange(
                $start === '' ? Marker::fromTimestamp(Bounds::MIN) : Marker::parse($start)->startOfDay(),
                $end === '' ? Marker::fromTimestamp(Bounds::MAX) : Marker::parse($end)->startOfDay()->addDays(1),
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("interaction.valid_range no es válido: {$e->getMessage()} Se espera ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD'], con start no posterior a end.", 0, $e);
        }
    }

    /** @return array{timelineSlot: int, timelineRowStart: ?int, timelineRowLimit: ?int} */
    private static function timeline(\Closure $get, string $view): array
    {
        $slots = (array) $get('timeline.slots', []);
        $window = (int) $get('timeline.window', 60);

        return [
            'timelineSlot' => (int) Duration::seconds($slots[$view] ?? '1h'),
            'timelineRowStart' => self::intOrNull($get('timeline.row_start')),
            'timelineRowLimit' => $window > 0 ? $window : null,
        ];
    }

    /** Con más filas que esto el timeline se virtualiza. */
    public static function virtualizeAfter(): int
    {
        return (int) config('kore-calendar.timeline.virtualize_after', 100);
    }

    /** Cambia la vista o la fecha sin recalcular el resto (navegación). */
    public function with(?string $view = null, ?Marker $date = null): self
    {
        return new self(
            $view ?? $this->view, $date ?? $this->date, $this->clock, $this->locale, $this->weekRules,
            $this->weekNumbers, $this->viewOptions, $this->layoutOptions, $this->slotDuration,
            $this->nowIndicator, $this->datesAboveResources, $this->defaultTimedSeconds,
            $this->defaultAllDayDays, $this->formats, $this->timelineSlot, $this->timelineRowStart, $this->timelineRowLimit, $this->timelineScrollTime,
            $this->editable, $this->selectable, $this->snapSeconds, $this->eventOverlap,
            $this->allDayMaintainDuration, $this->resourceEditable, $this->validRange, $this->eventClick,
            $this->businessHours, $this->eventConstraint, $this->selectConstraint, $this->selectOverlap, $this->highlightConflicts,
        );
    }

    /**
     * 'HH:MM' → segundos. Admite negativos ('-02:00') y más de 24 h ('26:00'),
     * que FullCalendar permite para extender el eje a los días vecinos.
     */
    public static function timeOfDay(string $value): int
    {
        if (! preg_match('/^(-)?(\d{1,3}):(\d{2})(?::(\d{2}))?$/', trim($value), $m)) {
            throw new InvalidArgumentException("Hora no válida: «{$value}». Se espera HH:MM, por ejemplo 08:00, 26:00 o -02:00.");
        }

        $seconds = (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) ($m[4] ?? 0);

        return $m[1] === '-' ? -$seconds : $seconds;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === false ? null : (int) $value;
    }
}
