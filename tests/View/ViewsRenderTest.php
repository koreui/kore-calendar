<?php

use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\View\CalendarRenderer;
use KoreUi\Calendar\View\CalendarSettings;

$render = function (string $view, array $events = [], ?ResourceSet $resources = null) {
    $calendar = CalendarRenderer::render(CalendarSettings::make(['view' => $view, 'date' => '2026-10-16', 'timezone' => 'UTC']), $events, $resources);
    $component = match ($view) {
        'month' => 'month',
        'list' => 'list',
        default => 'time-grid',
    };

    return (string) test()->blade("<x-kore::calendar.{$component} :calendar=\"\$calendar\" />", ['calendar' => $calendar]);
};

it('el mes es una tabla accesible con hoy marcado y una sola parada de tabulación', function () use ($render) {
    $html = $render('month', [['id' => 1, 'title' => 'Congreso', 'start' => '2026-10-14', 'end' => '2026-10-17']]);

    expect($html)->toContain('role="table"')
        ->toContain('aria-current="date"')
        ->toContain('Congreso')
        ->toContain('--kc-span: 3')
        ->and(substr_count($html, 'tabindex="0"'))->toBe(1);
});

it('un evento con url es un enlace', function () use ($render) {
    expect($render('month', [['id' => 1, 'title' => 'Ver', 'start' => '2026-10-14', 'url' => '/eventos/1']]))
        ->toContain('href="/eventos/1"');
});

it('el color del evento viaja como token, no como clase', function () use ($render) {
    expect($render('month', [['id' => 1, 'title' => 'A', 'start' => '2026-10-14', 'color' => 'chart-3']]))
        ->toContain('--kore-event: var(--kore-chart-3);');
});

it('la semana dibuja las posiciones del servidor', function () use ($render) {
    expect($render('week', [['id' => 1, 'title' => 'Revisión', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:30']]))
        ->toContain('--kc-top: 41.6667; --kc-height: 6.25')
        ->toContain('Revisión');
});

it('las columnas por recurso llevan el título del recurso en la cabecera', function () use ($render) {
    $html = $render('resource-day', [['id' => 1, 'title' => 'X', 'start' => '2026-10-16 09:00', 'end' => '2026-10-16 10:00', 'resourceIds' => ['a']]],
        new ResourceSet([['id' => 'a', 'title' => 'Sala Ámbar'], ['id' => 'b', 'title' => 'Sala Bruma']]));

    expect($html)->toContain('Sala Ámbar')->toContain('Sala Bruma');
});

it('la agenda vacía lo anuncia con role=status', function () use ($render) {
    expect($render('list'))->toContain('role="status"')->toContain('No hay eventos');
});

it('el "+N más" abre un diálogo con los eventos de la celda', function () {
    config(['kore-calendar.month.max_events' => 1]);
    $calendar = CalendarRenderer::render(CalendarSettings::make(['date' => '2026-10-16', 'timezone' => 'UTC']), [
        ['id' => 1, 'title' => 'Uno', 'start' => '2026-10-14'],
        ['id' => 2, 'title' => 'Dos', 'start' => '2026-10-14'],
    ]);

    $html = (string) $this->blade('<x-kore::calendar.month :calendar="$calendar" />', ['calendar' => $calendar]);

    expect($html)->toContain('+1 más')->toContain('role="dialog"')->toContain('aria-haspopup="dialog"');
});

it('cada evento lleva su instanceId: las ocurrencias de una serie comparten el id y solo ese las distingue', function () {
    $calendar = CalendarRenderer::render(CalendarSettings::make(['date' => '2026-10-16', 'timezone' => 'UTC']), [
        ['id' => 'serie', 'title' => 'Standup', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 09:30', 'rrule' => 'FREQ=DAILY;COUNT=3'],
        ['id' => 7, 'title' => 'Suelto', 'start' => '2026-10-14 15:00'],
    ]);

    $html = (string) $this->blade('<x-kore::calendar.month :calendar="$calendar" />', ['calendar' => $calendar]);

    preg_match_all('/data-kore-instance="([^"]+)"/', $html, $instancias);
    $monday = \KoreUi\Calendar\Time\Marker::parse('2026-10-12 09:00')->ts;

    expect($instancias[1])->toContain("serie:{$monday}", 'serie:'.($monday + 86_400), 'serie:'.($monday + 2 * 86_400))
        ->and($instancias[1])->toContain('7:'.\KoreUi\Calendar\Time\Marker::parse('2026-10-14 15:00')->ts)
        // El id del evento sigue ahí (el foco, los tests y el HTML anterior lo usan) y es el mismo en las tres.
        ->and(substr_count($html, 'data-kore-event="serie"'))->toBe(3);
});
