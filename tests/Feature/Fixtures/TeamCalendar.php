<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

use Carbon\CarbonImmutable;
use KoreUi\Calendar\KoreCalendar;

class TeamCalendar extends KoreCalendar
{
    /** Lo que se pidió a events(), para comprobar el rango. */
    public static array $asked = [];

    public function configure(): void
    {
        $this->views(['month', 'week', 'list', 'resource-day'])->timezone('Europe/Madrid')->slotMinTime('08:00')->slotMaxTime('20:00');
    }

    public function events(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        static::$asked[] = [$from->toIso8601String(), $to->toIso8601String()];

        return [
            ['id' => 1, 'title' => 'Revisión trimestral', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:30', 'resourceIds' => ['a']],
            ['id' => 2, 'title' => 'Congreso', 'start' => '2026-10-15', 'end' => '2026-10-17'],
        ];
    }

    public function resources(): iterable
    {
        return [['id' => 'a', 'title' => 'Sala Ámbar']];
    }
}
