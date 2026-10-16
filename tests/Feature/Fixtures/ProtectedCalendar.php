<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\KoreCalendar;

/** Como lo enseñan los docs: `events()`, `resources()` y `configure()` son `protected`. */
class ProtectedCalendar extends KoreCalendar
{
    protected function configure(): void
    {
        $this->defaultView('week')->timezone('Europe/Madrid')->firstDay(1);
    }

    protected function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return [
            ['id' => 1, 'title' => 'Revisión', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:30', 'meta' => ['secreto' => 'ASISTENTES-PRIVADOS']],
        ];
    }

    protected function resources(): iterable
    {
        return [['id' => 'a', 'title' => 'Sala A']];
    }
}
