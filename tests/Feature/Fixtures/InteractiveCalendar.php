<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Interaction\EventMutation;
use KoreUi\Calendar\Interaction\Selection;
use KoreUi\Calendar\KoreCalendar;

/**
 * Un calendario editable cuyo estado vive en propiedades estáticas, para que cada
 * test lo cambie (eventos, ajustes, lo que responden los hooks) sin una clase por
 * caso: Livewire crea la instancia, así que el test no puede inyectarle nada.
 * `fresh()` en el `beforeEach`.
 */
class InteractiveCalendar extends KoreCalendar
{
    /** Ajustes extra con la forma del config ('interaction.event_overlap' => false). Ganan a configure(). */
    public static array $config = [];

    /** Los eventos que devuelve events(). `null` = los de siempre. */
    public static ?array $events = null;

    public static array $resources = [];

    /** @var list<array{0: CalendarEvent, 1: EventMutation, 2: int}> Con el nivel de transacción en el que corrió el hook. */
    public static array $moved = [];

    public static array $resized = [];

    /** @var list<Selection> */
    public static array $selected = [];

    /** Qué devuelven persistMove/persistResize: un valor, o un Closure(CalendarEvent, EventMutation). */
    public static mixed $hookResult = null;

    /** Cuántas veces se llamó a events(). */
    public static int $eventCalls = 0;

    public static function fresh(): void
    {
        static::$config = [];
        static::$events = null;
        static::$resources = [];
        static::$moved = [];
        static::$resized = [];
        static::$selected = [];
        static::$hookResult = null;
        static::$eventCalls = 0;
    }

    /** @return list<array<string, mixed>> */
    public static function defaultEvents(): array
    {
        return [
            ['id' => 1, 'title' => 'Revisión', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:30'],
            ['id' => 2, 'title' => 'Congreso', 'start' => '2026-10-15', 'end' => '2026-10-17'],
            ['id' => 3, 'title' => 'Bloqueo', 'start' => '2026-10-16 15:00', 'end' => '2026-10-16 16:00', 'overlap' => false],
        ];
    }

    public function configure(): void
    {
        $this->defaultView('week')
            ->views(['month', 'week', 'day', 'list', 'resource-week'])
            ->timezone('Europe/Madrid')
            ->firstDay(1)
            ->editable()
            ->selectable()
            ->snap('15m');

        foreach (static::$config as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        static::$eventCalls++;

        return static::$events ?? static::defaultEvents();
    }

    public function resources(): iterable
    {
        return static::$resources;
    }

    protected function persistMove(CalendarEvent $event, EventMutation $mutation)
    {
        static::$moved[] = [$event, $mutation, static::transactionLevel()];

        return static::result($event, $mutation);
    }

    protected function persistResize(CalendarEvent $event, EventMutation $mutation)
    {
        static::$resized[] = [$event, $mutation, static::transactionLevel()];

        return static::result($event, $mutation);
    }

    protected function onDateSelect(Selection $selection): void
    {
        static::$selected[] = $selection;
    }

    private static function result(CalendarEvent $event, EventMutation $mutation): mixed
    {
        return static::$hookResult instanceof Closure ? (static::$hookResult)($event, $mutation) : static::$hookResult;
    }

    /** -1 si no hay base de datos a la que preguntar: sin `report`, que ensuciaría los tests de excepciones. */
    private static function transactionLevel(): int
    {
        return rescue(fn () => DB::transactionLevel(), -1, false);
    }

    /** Solo para tests: conflicts() es protegido (ver KoreCalendar). */
    public function koreTestConflicts(): array
    {
        return $this->conflicts();
    }
}
