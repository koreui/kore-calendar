<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\View\CalendarSettings;

it('toma la configuración del paquete', function () {
    $s = CalendarSettings::make(['date' => '2026-10-16']);

    expect($s->view)->toBe('month')
        ->and($s->slotDuration)->toBe(1800)
        ->and($s->layoutOptions->slotMax)->toBe(86400)
        ->and($s->defaultTimedSeconds)->toBe(3600)
        ->and((string) $s->date)->toBe('2026-10-16 00:00:00');
});

it('las props ganan a la configuración', function () {
    $s = CalendarSettings::make([
        'view' => 'week',
        'timezone' => 'Europe/Madrid',
        'first_day' => 0,
        'time.slot_duration' => '15m',
        'time.slot_min_time' => '08:00',
        'time.slot_max_time' => '26:00',
        'month.max_events' => 3,
    ]);

    expect($s->view)->toBe('week')
        ->and($s->clock->name())->toBe('Europe/Madrid')
        ->and($s->weekRules->dow)->toBe(0)
        ->and($s->slotDuration)->toBe(900)
        ->and($s->layoutOptions->slotMin)->toBe(8 * 3600)
        ->and($s->layoutOptions->slotMax)->toBe(26 * 3600)
        ->and($s->layoutOptions->maxEvents)->toBe(3);
});

it('el primer día por defecto es el del datepicker de kore-ui', function () {
    config(['kore-ui.form.datepicker.start_of_week' => 0]);

    expect(CalendarSettings::make()->weekRules->dow)->toBe(0);
});

it('sin fecha usa "ahora" en la zona de la vista', function () {
    CarbonImmutable::setTestNow('2026-10-16 23:30:00 UTC');

    expect(CalendarSettings::make(['timezone' => 'Europe/Madrid'])->date->toDateString())->toBe('2026-10-17');

    CarbonImmutable::setTestNow();
});

it('lee horas del día negativas y de más de 24 h', function () {
    expect(CalendarSettings::timeOfDay('08:30'))->toBe(30600)
        ->and(CalendarSettings::timeOfDay('-02:00'))->toBe(-7200)
        ->and(CalendarSettings::timeOfDay('26:00'))->toBe(93600);
});

it('rechaza horas mal escritas y un eje vacío', function () {
    expect(fn () => CalendarSettings::timeOfDay('8h'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CalendarSettings::make(['time.slot_min_time' => '18:00', 'time.slot_max_time' => '08:00']))->toThrow(InvalidArgumentException::class, 'posterior');
});
