<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Interaction\EventMutation;
use KoreUi\Calendar\Interaction\Selection;
use KoreUi\Calendar\KoreCalendar;

/**
 * Como lo escribe quien copia los docs de antes: TODO público (`events`, `resources`, `configure` y
 * los hooks). Livewire dejaría llamar a cada uno desde el navegador; el paquete lo impide.
 * `ping()` es un método público cualquiera, que sí se puede llamar: el control del test.
 */
class PublicHooksCalendar extends KoreCalendar
{
    public static array $log = [];

    public function configure(): void
    {
        $this->defaultView('week')->timezone('Europe/Madrid')->firstDay(1)->editable()->selectable()->eventForm();
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        static::$log[] = 'events';

        return [
            ['id' => 1, 'title' => 'Revisión', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:30', 'meta' => ['secreto' => 'ASISTENTES-PRIVADOS']],
        ];
    }

    public function resources(): iterable
    {
        return [];
    }

    public function persistMove(CalendarEvent $event, EventMutation $mutation)
    {
        static::$log[] = 'persistMove';
    }

    public function persistResize(CalendarEvent $event, EventMutation $mutation)
    {
        static::$log[] = 'persistResize';
    }

    public function createEvent(array $data)
    {
        static::$log[] = 'createEvent';
    }

    public function updateEvent(CalendarEvent $event, array $data)
    {
        static::$log[] = 'updateEvent';
    }

    public function deleteEvent(CalendarEvent $event)
    {
        static::$log[] = 'deleteEvent';
    }

    public function onDateSelect(Selection $selection): void
    {
        static::$log[] = 'onDateSelect';
    }

    public function onEventClick(CalendarEvent $event): void
    {
        static::$log[] = 'onEventClick';
    }

    public function conflicts(): array
    {
        static::$log[] = 'conflicts';

        return [];
    }

    public function ping(): string
    {
        return 'pong';
    }
}
