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
    app()->setLocale('es');

    expect($render('list'))->toContain('role="status"')->toContain('No hay eventos');
});

it('el "+N más" abre un diálogo con los eventos de la celda', function () {
    app()->setLocale('es');
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

// ── Días de relleno del mes ───────────────────────────────────────────

it('los días de los meses vecinos usan --kore-calendar-other-month, en el número y en el fondo', function () use ($render) {
    // Octubre de 2026 con la semana en lunes: 28, 29 y 30 de septiembre y 1 a 8 de noviembre = 11 celdas de relleno.
    $html = $render('month');

    expect(substr_count($html, 'kore-calendar-day-other'))->toBe(11)
        ->and(substr_count($html, 'kore-calendar-other-number'))->toBe(11);

    // El número ya no se atenúa con una clase de Tailwind que ignora el token.
    preg_match_all('/<span\s[^>]*class="([^"]*kore-calendar-other-number[^"]*)"/s', $html, $numeros);

    expect($numeros[1])->toHaveCount(11);

    foreach ($numeros[1] as $clases) {
        expect($clases)->not->toContain('text-kore-muted-fg');
    }
});

it('hoy en un mes vecino conserva su resalte y no se atenúa como relleno', function () {
    // Hoy es el 2 de noviembre: la vista de octubre lo enseña como día de relleno.
    \Carbon\CarbonImmutable::setTestNow('2026-11-02 12:00:00 UTC');

    try {
        $calendar = CalendarRenderer::render(CalendarSettings::make(['view' => 'month', 'date' => '2026-10-16', 'timezone' => 'UTC']), []);
        $html = (string) test()->blade('<x-kore::calendar.month :calendar="$calendar" />', ['calendar' => $calendar]);
    } finally {
        \Carbon\CarbonImmutable::setTestNow();
    }

    preg_match_all('/<div[^>]*role="cell"[^>]*>/s', $html, $celdas);
    $hoy = array_values(array_filter($celdas[0], fn ($c) => str_contains($c, 'aria-current="date"')));

    expect($hoy)->toHaveCount(1)
        ->and($hoy[0])->toContain('kore-calendar-day-today')->not->toContain('kore-calendar-day-other')
        // Las otras diez celdas de relleno sí se atenúan.
        ->and(substr_count($html, 'kore-calendar-day-other'))->toBe(10);
});

it('los días de relleno deshabilitados siguen con su fondo de deshabilitado, no con el de relleno', function () {
    $calendar = CalendarRenderer::render(CalendarSettings::make(['view' => 'month', 'date' => '2026-10-16', 'timezone' => 'UTC', 'month.show_non_current_dates' => false]), []);
    $html = (string) test()->blade('<x-kore::calendar.month :calendar="$calendar" />', ['calendar' => $calendar]);

    preg_match_all('/<div[^>]*role="cell"[^>]*>/s', $html, $celdas);

    $deshabilitadas = array_filter($celdas[0], fn ($c) => str_contains($c, 'bg-kore-muted/40'));

    // Hay deshabilitadas (si no, el test no miraría nada) y ninguna lleva además el fondo de relleno.
    expect($deshabilitadas)->not->toBeEmpty()
        ->and(array_filter($deshabilitadas, fn ($c) => str_contains($c, 'kore-calendar-day-other')))->toBeEmpty();
});

it('el CSS usa el token de los días de relleno y lo define para claro y oscuro', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/kore-calendar.css');
    preg_match("/\.dark,\s*\[data-theme='dark'\]\s*\{([^}]*)\}/", $css, $oscuro);

    // Número y fondo salen del mismo token, así que cambiarlo cambia las dos cosas.
    expect($css)->toMatch('/\.kore-calendar-other-number\s*\{[^}]*color:\s*var\(--kore-calendar-other-month\)/')
        ->and($css)->toMatch('/\.kore-calendar-day-other\s*\{[^}]*background:[^;}]*var\(--kore-calendar-other-month\)/')
        // El de hoy se declara después: gana al de relleno cuando coinciden.
        ->and(strpos($css, '.kore-calendar-day-other'))->toBeLessThan(strpos($css, '.kore-calendar-day-today'))
        ->and($oscuro[1] ?? '')->toContain('--kore-calendar-other-month:');
});
