<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use Closure;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Interaction\EventMutation;
use KoreUi\Calendar\KoreCalendar;
use KoreUi\Calendar\Recurrence\RecurrenceEditor;

/**
 * Un calendario con una serie, editable y con formulario, cuyo almacén vive en
 * memoria y se expande de verdad (`events()` devuelve las filas tal cual y es
 * `EventCollector` quien genera las ocurrencias).
 *
 * Con `$store = false` los hooks solo anotan lo que reciben. Con `$store = true`
 * guardan además lo que dice cada alcance, con `RecurrenceEditor` y nada más:
 * es el `persistMove()` que documenta `docs/kore-calendar.md`, y el test de flujo
 * completo comprueba que, al volver a expandir lo guardado, sale lo que se pidió.
 *
 * Las filas de siempre, semana del 12 al 18 de octubre de 2026 en Europe/Madrid:
 *   s  «Standup»  cada día 09:00-09:30, 14 veces (del lunes 12 al domingo 25)
 *   p  «Comida»   mié 14, 14:00-15:00 (un evento normal)
 */
class RecurringCalendar extends KoreCalendar
{
    /** @var list<array<string, mixed>>|null */
    public static ?array $rows = null;

    public static bool $store = false;

    public static bool $undoable = false;

    /** Ajustes extra con la forma del config ('locale' => 'en'). Ganan a configure(). */
    public static array $config = [];

    /** @var list<array{0: CalendarEvent, 1: EventMutation}> */
    public static array $moved = [];

    /** @var list<array{0: CalendarEvent, 1: EventMutation}> */
    public static array $resized = [];

    /** @var list<array{0: CalendarEvent, 1: array<string, mixed>}> */
    public static array $updated = [];

    /** @var list<array{0: CalendarEvent, 1: array<string, mixed>}> */
    public static array $deleted = [];

    /** Qué devuelven los hooks: un valor, o un Closure(...args). */
    public static mixed $hookResult = null;

    private static int $seq = 0;

    public static function fresh(): void
    {
        static::$rows = null;
        static::$store = false;
        static::$undoable = false;
        static::$config = [];
        static::$moved = [];
        static::$resized = [];
        static::$updated = [];
        static::$deleted = [];
        static::$hookResult = null;
        static::$seq = 0;
    }

    /** @return list<array<string, mixed>> */
    public static function defaultRows(): array
    {
        return [
            ['id' => 's', 'title' => 'Standup', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 09:30', 'rrule' => 'FREQ=DAILY;COUNT=14'],
            ['id' => 'p', 'title' => 'Comida', 'start' => '2026-10-14 14:00', 'end' => '2026-10-14 15:00'],
        ];
    }

    public function configure(): void
    {
        $this->defaultView('week')
            ->views(['month', 'week', 'day', 'list'])
            ->timezone('Europe/Madrid')
            ->firstDay(1)
            ->editable()
            ->eventForm()
            ->snap('15m');

        if (static::$undoable) {
            $this->undoable();
        }

        foreach (static::$config as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return static::$rows ??= static::defaultRows();
    }

    // ── Hooks ───────────────────────────────────────────────────────────

    protected function persistMove(CalendarEvent $event, EventMutation $mutation)
    {
        static::$moved[] = [$event, $mutation];

        return static::$store ? static::guard(fn () => static::reshape($event, $mutation, resize: false)) : static::result($event, $mutation);
    }

    protected function persistResize(CalendarEvent $event, EventMutation $mutation)
    {
        static::$resized[] = [$event, $mutation];

        return static::$store ? static::guard(fn () => static::reshape($event, $mutation, resize: true)) : static::result($event, $mutation);
    }

    protected function updateEvent(CalendarEvent $event, array $data)
    {
        static::$updated[] = [$event, $data];

        return static::$store ? static::guard(fn () => static::edit($event, $data)) : static::result($event, $data);
    }

    // Sin tipo del segundo parámetro en la base: este lo declara para recibir `$data`.
    protected function deleteEvent(CalendarEvent $event, array $data = [])
    {
        static::$deleted[] = [$event, $data];

        return static::$store ? static::guard(fn () => static::remove($event, $data)) : static::result($event, $data);
    }

    private static function result(mixed ...$args): mixed
    {
        return static::$hookResult instanceof Closure ? (static::$hookResult)(...$args) : static::$hookResult;
    }

    private static function guard(Closure $save): mixed
    {
        $save();

        return static::result();
    }

    // ── Lo que guarda cada alcance (con RecurrenceEditor y nada más) ─────

    /** Mover y redimensionar. */
    private static function reshape(CalendarEvent $event, EventMutation $m, bool $resize): void
    {
        $to = ['start' => static::wall($m->allDay, $m->startMarker), 'end' => $m->end === null ? null : static::wall($m->allDay, $m->endMarker)];

        if ($m->scope === null) {
            static::patch($event->id, $to);

            return;
        }

        $series = static::series($m->seriesId);

        match ($m->scope) {
            'this' => static::override($event, $series, $m, $m),
            'following' => RecurrenceEditor::isFirst($series, $m)
                ? static::patch($series->id, $resize ? RecurrenceEditor::resize($series, $m) : RecurrenceEditor::shift($series, $m->deltaSeconds))
                : static::split($series, $m, $to),
            'all' => static::patch($series->id, $resize ? RecurrenceEditor::resize($series, $m) : RecurrenceEditor::shift($series, $m->deltaSeconds)),
        };
    }

    /** @param  array<string, mixed>  $data */
    private static function edit(CalendarEvent $event, array $data): void
    {
        $to = ['start' => $data['start'], 'end' => $data['end'], 'title' => $data['title']];

        if (! isset($data['scope'])) {
            static::patch($event->id, [...$to, 'allDay' => $data['allDay']]);

            return;
        }

        $series = static::series($data['seriesId']);

        match ($data['scope']) {
            'this' => static::override($event, $series, $data, $data),
            'following' => RecurrenceEditor::isFirst($series, $data) ? static::patchAll($series, $data) : static::split($series, $data, $to),
            'all' => static::patchAll($series, $data),
        };
    }

    /** @param  array<string, mixed>  $data */
    private static function remove(CalendarEvent $event, array $data): void
    {
        if (! isset($data['scope'])) {
            static::$rows = array_values(array_filter(static::$rows, fn ($r) => $r['id'] !== $event->id));

            return;
        }

        $series = static::series($data['seriesId']);

        match ($data['scope']) {
            // La generada se excluye; si ya era una excepción, su fila se borra también.
            'this' => static::exclude($event, $series, $data),
            'following' => RecurrenceEditor::isFirst($series, $data) ? static::dropSeries($series) : static::cut($series, $data),
            'all' => static::dropSeries($series),
        };
    }

    // ── Piezas ──────────────────────────────────────────────────────────

    private static function series(string|int $id): CalendarEvent
    {
        foreach (static::$rows as $row) {
            if ($row['id'] === $id) {
                return CalendarEvent::fromArray($row);
            }
        }

        throw new \RuntimeException("No hay serie «{$id}».");
    }

    /** @param  array<string, mixed>  $changes */
    private static function patch(string|int $id, array $changes): void
    {
        foreach (static::$rows as &$row) {
            if ($row['id'] === $id) {
                $row = array_filter([...$row, ...$changes], fn ($v, $k) => $v !== null || $k !== 'end', ARRAY_FILTER_USE_BOTH);
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    private static function patchAll(CalendarEvent $series, array $data): void
    {
        static::patch($series->id, [...RecurrenceEditor::shift($series, $data['deltaSeconds']), 'title' => $data['title']]);
    }

    private static function override(CalendarEvent $event, CalendarEvent $series, EventMutation|array $occurrence, EventMutation|array $change): void
    {
        $override = RecurrenceEditor::override($series, $occurrence, $change);

        if ($event->isOverride()) {
            // Ya era una excepción: se actualiza su fila, no se crea otra.
            static::patch($event->id, $override);

            return;
        }

        static::$rows[] = ['id' => 'o'.(++static::$seq), ...$override];
    }

    /** @param  array<string, mixed>  $new */
    private static function split(CalendarEvent $series, EventMutation|array $occurrence, array $new): void
    {
        $cut = RecurrenceEditor::splitAt($series, $occurrence);
        $row = collect(static::$rows)->firstWhere('id', $series->id);

        static::patch($series->id, ['rrule' => $cut['before']]);
        static::$rows[] = [...$row, ...$new, 'id' => 'n'.(++static::$seq), 'rrule' => $cut['after'], 'exdates' => []];
    }

    /** @param  array<string, mixed>  $data */
    private static function cut(CalendarEvent $series, array $data): void
    {
        static::patch($series->id, ['rrule' => RecurrenceEditor::splitAt($series, $data)['before']]);
    }

    /** @param  array<string, mixed>  $data */
    private static function exclude(CalendarEvent $event, CalendarEvent $series, array $data): void
    {
        if ($event->isOverride()) {
            static::$rows = array_values(array_filter(static::$rows, fn ($r) => $r['id'] !== $event->id));
        }

        static::patch($series->id, ['exdates' => RecurrenceEditor::exclude($series, $data)]);
    }

    private static function dropSeries(CalendarEvent $series): void
    {
        static::$rows = array_values(array_filter(static::$rows, fn ($r) => $r['id'] !== $series->id && ($r['recurrenceOf'] ?? null) !== $series->id));
    }

    private static function wall(bool $allDay, \KoreUi\Calendar\Time\Marker $marker): string
    {
        return $allDay ? $marker->toDateString() : $marker->toDateTimeString();
    }
}
