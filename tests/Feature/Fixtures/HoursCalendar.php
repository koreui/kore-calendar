<?php

namespace KoreUi\Calendar\Tests\Feature\Fixtures;

/**
 * `InteractiveCalendar` con el horario laboral y los conflictos activados por la API
 * fluida, para probar que `configure()` los cablea. Los ajustes de `$config` se aplican
 * DESPUÉS (`parent::configure()` los pone al final), así que un test puede apagar uno.
 */
class HoursCalendar extends InteractiveCalendar
{
    public function configure(): void
    {
        $this->businessHours()
            ->eventConstraint()
            ->selectConstraint()
            ->selectOverlap(false)
            ->highlightConflicts();

        parent::configure();
    }
}
