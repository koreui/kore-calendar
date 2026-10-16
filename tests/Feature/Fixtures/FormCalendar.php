<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Interaction\EventMutation;
use KoreUi\Calendar\KoreCalendar;

/**
 * Un calendario con el formulario de evento (y, si el test lo pide, `undoable()`).
 *
 * Como `InteractiveCalendar`, su estado vive en propiedades estáticas para que cada
 * test lo cambie sin una clase por caso. A diferencia de aquel, NO sobrescribe
 * `onDateSelect()` ni `onEventClick()`: son los de la clase base los que se prueban.
 */
class FormCalendar extends KoreCalendar
{
    /** Ajustes extra con la forma del config. Ganan a configure(). */
    public static array $config = [];

    /**
     * Los eventos que devuelve events(). `null` = los de siempre, copiados aquí la primera vez.
     * Es el almacén de este calendario de pruebas: mover y redimensionar con éxito lo CAMBIA,
     * como haría una base de datos. Sin eso, deshacer vería siempre el evento donde empezó
     * y no habría nada que deshacer.
     */
    public static ?array $events = null;

    public static array $resources = [];

    public static bool $form = true;

    public static bool $undoable = false;

    /** @var list<array{0: array, 1: int}> Los datos de createEvent, con el nivel de transacción. */
    public static array $created = [];

    /** @var list<array{0: CalendarEvent, 1: array, 2: int}> */
    public static array $updated = [];

    /** @var list<array{0: CalendarEvent, 1: int}> */
    public static array $deleted = [];

    /** @var list<array{0: CalendarEvent, 1: EventMutation}> */
    public static array $moved = [];

    /** @var list<array{0: CalendarEvent, 1: EventMutation}> */
    public static array $resized = [];

    /** Qué devuelven los hooks: un valor, o un Closure(...args). */
    public static mixed $hookResult = null;

    /** Qué devuelven persistMove/persistResize. */
    public static mixed $persistResult = null;

    public static function fresh(): void
    {
        static::$config = [];
        static::$events = null;
        static::$resources = [];
        static::$form = true;
        static::$undoable = false;
        static::$created = [];
        static::$updated = [];
        static::$deleted = [];
        static::$moved = [];
        static::$resized = [];
        static::$hookResult = null;
        static::$persistResult = null;
    }

    public function configure(): void
    {
        // `editable()`: el formulario de un calendario que no lo es no puede cambiar fechas ni eliminar
        // (tests/Feature/EventFormRulesTest.php). Un test lo apaga con `'interaction.editable' => false` en `$config`.
        $this->defaultView('week')
            ->views(['month', 'week', 'day', 'list', 'resource-week'])
            ->timezone('Europe/Madrid')
            ->firstDay(1)
            ->snap('15m')
            ->editable();

        if (static::$form) {
            $this->eventForm();
        }

        if (static::$undoable) {
            $this->undoable();
        }

        foreach (static::$config as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return static::$events ??= InteractiveCalendar::defaultEvents();
    }

    public function resources(): iterable
    {
        return static::$resources;
    }

    protected function createEvent(array $data)
    {
        static::$created[] = [$data, static::transactionLevel()];

        return static::result(func_get_args());
    }

    protected function updateEvent(CalendarEvent $event, array $data)
    {
        static::$updated[] = [$event, $data, static::transactionLevel()];

        return static::result(func_get_args());
    }

    protected function deleteEvent(CalendarEvent $event)
    {
        static::$deleted[] = [$event, static::transactionLevel()];

        return static::result(func_get_args());
    }

    protected function persistMove(CalendarEvent $event, EventMutation $mutation)
    {
        static::$moved[] = [$event, $mutation];

        return static::persisted($event, $mutation);
    }

    protected function persistResize(CalendarEvent $event, EventMutation $mutation)
    {
        static::$resized[] = [$event, $mutation];

        return static::persisted($event, $mutation);
    }

    /** El hook decide; si acepta, el cambio llega al almacén. */
    private static function persisted(CalendarEvent $event, EventMutation $mutation): mixed
    {
        $result = static::$persistResult instanceof Closure ? (static::$persistResult)($event, $mutation) : static::$persistResult;

        if ($result === null || $result === true) {
            static::$events ??= InteractiveCalendar::defaultEvents();

            foreach (static::$events as &$row) {
                if ($row['id'] !== $event->id) {
                    continue;
                }

                $row['allDay'] = $mutation->allDay;
                $row['start'] = $mutation->allDay ? $mutation->startDate() : $mutation->start->format('c');
                $row['resourceIds'] = $mutation->resourceIds;
                unset($row['end']);

                if ($mutation->end !== null) {
                    $row['end'] = $mutation->allDay ? $mutation->endDate() : $mutation->end->format('c');
                }
            }
        }

        return $result;
    }

    private static function result(array $args): mixed
    {
        return static::$hookResult instanceof Closure ? (static::$hookResult)(...$args) : static::$hookResult;
    }

    /** -1 si no hay base de datos a la que preguntar. */
    private static function transactionLevel(): int
    {
        return rescue(fn () => DB::transactionLevel(), -1, false);
    }
}
