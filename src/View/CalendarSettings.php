<?php

namespace KoreUi\Calendar\View;

use DateTimeInterface;
use InvalidArgumentException;
use KoreUi\Calendar\Layout\EventOrder;
use KoreUi\Calendar\Layout\LayoutOptions;
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
            slotDuration: (int) Duration::seconds($get('time.slot_duration', '30m')),
            nowIndicator: (bool) $get('now_indicator', true),
            datesAboveResources: (bool) $get('time_grid.dates_above_resources', false),
            defaultTimedSeconds: (int) Duration::seconds($get('time.default_timed_duration', '1h')),
            defaultAllDayDays: (int) $get('time.default_all_day_duration', 1),
            formats: array_merge((array) config('kore-calendar.formats', []), (array) ($overrides['formats'] ?? [])),
        );
    }

    /** Cambia la vista o la fecha sin recalcular el resto (navegación). */
    public function with(?string $view = null, ?Marker $date = null): self
    {
        return new self(
            $view ?? $this->view, $date ?? $this->date, $this->clock, $this->locale, $this->weekRules,
            $this->weekNumbers, $this->viewOptions, $this->layoutOptions, $this->slotDuration,
            $this->nowIndicator, $this->datesAboveResources, $this->defaultTimedSeconds,
            $this->defaultAllDayDays, $this->formats,
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
