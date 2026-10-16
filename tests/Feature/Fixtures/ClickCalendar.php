<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\KoreCalendar;

/** Sin formulario, pero con su propio `onEventClick()`: los eventos tienen que ser pulsables igualmente. */
class ClickCalendar extends KoreCalendar
{
    /** @var list<CalendarEvent> */
    public static array $clicked = [];

    /** `false` apaga el clic explícitamente aunque se sobrescriba el hook. */
    public static ?bool $eventClick = null;

    public function configure(): void
    {
        $this->defaultView('week')->views(['week'])->timezone('Europe/Madrid')->firstDay(1);

        if (static::$eventClick !== null) {
            $this->eventClick(static::$eventClick);
        }
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return InteractiveCalendar::defaultEvents();
    }

    protected function onEventClick(CalendarEvent $event): void
    {
        static::$clicked[] = $event;
    }
}
