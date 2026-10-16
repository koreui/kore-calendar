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
    // Con un calendario editable: los eventos de solo lectura no llevan data-kore-event ni data-kore-instance.
    $calendar = CalendarRenderer::render(CalendarSettings::make(['date' => '2026-10-16', 'timezone' => 'UTC', 'interaction.editable' => true]), [
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


// ── Atributos de datos del evento ──────────────────────────────────────

/**
 * La etiqueta de apertura del evento (sola, para mirar sus atributos). Se reconoce por la clase
 * `kore-calendar-event`, que llevan todos menos el punto de la vista mes (`kore-calendar-event-dot`).
 */
$eventTag = function (array $event, array $settings = [], string $view = 'week') {
    $calendar = CalendarRenderer::render(CalendarSettings::make(['view' => $view, 'date' => '2026-10-14', 'timezone' => 'UTC', ...$settings]), [$event]);
    $component = $view === 'month' ? 'month' : 'time-grid';
    $html = (string) test()->blade("<x-kore::calendar.{$component} :calendar=\"\$calendar\" />", ['calendar' => $calendar]);

    preg_match_all('/<(?:div|a)\b[^>]*\bkore-calendar-event(?:-dot)?\b[^>]*>/s', $html, $tags);
    // Las etiquetas con la clase del evento: la del propio evento y, si la hay, ninguna otra.
    expect($tags[0])->toHaveCount(1);

    return $tags[0][0];
};

$timed = ['id' => 1, 'title' => 'Reunión "A"', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00'];
$allDay = ['id' => 2, 'title' => 'Congreso', 'start' => '2026-10-14', 'allDay' => true];
$dead = ['data-kore-calendar-event', 'data-kore-event', 'data-kore-instance', 'data-title', 'data-start', 'data-end', 'data-all-day', 'data-movable', 'data-resizable', 'data-clickable', 'tabindex'];

it('un evento de solo lectura no lleva ningún atributo de datos', function () use ($eventTag, $timed, $allDay, $dead) {
    foreach ([$timed, $allDay, [...$timed, 'url' => 'https://example.test/1']] as $event) {
        $tag = $eventTag($event);

        foreach ($dead as $attribute) {
            expect($tag)->not->toContain($attribute);
        }
    }
});

it('data-kore-calendar-event no sale en ningún caso: nadie lo lee', function () use ($eventTag, $timed, $allDay) {
    foreach ([[], ['interaction.editable' => true], ['interaction.editable' => true, 'interaction.event_click' => true]] as $settings) {
        foreach ([$timed, $allDay] as $event) {
            expect($eventTag($event, $settings))->not->toContain('data-kore-calendar-event');
        }
    }
});

it('un evento movible lleva su id, su instancia y los datos del gesto', function () use ($eventTag, $timed) {
    $tag = $eventTag($timed, ['interaction.editable' => true]);
    $start = \KoreUi\Calendar\Time\Marker::parse('2026-10-14 10:00')->ts;

    expect($tag)->toContain('data-kore-event="1"')
        ->toContain('data-kore-instance="1:'.$start.'"')
        ->toContain('data-title="Reunión &quot;A&quot;"')
        ->toContain('data-start="'.$start.'"')
        ->toContain('data-end="'.($start + 3600).'"')
        ->toContain('data-movable')
        ->toContain('data-resizable')
        // Un evento con hora: el JS lee `data-all-day === '1'`, y su ausencia es «no».
        ->not->toContain('data-all-day');
});

it('data-all-day solo sale, con valor 1, en un evento de día completo movible', function () use ($eventTag, $allDay) {
    $tag = $eventTag($allDay, ['interaction.editable' => true]);

    expect($tag)->toContain('data-all-day="1"')->not->toContain('data-all-day="0"');
});

it('un evento solo pulsable lleva id e instancia, pero no los datos del gesto', function () use ($eventTag, $timed) {
    $tag = $eventTag($timed, ['interaction.event_click' => true]);

    expect($tag)->toContain('data-kore-event="1"')
        ->toContain('data-kore-instance="1:')
        ->toContain('data-clickable')
        ->not->toContain('data-movable')
        ->not->toContain('data-title')
        ->not->toContain('data-start')
        ->not->toContain('data-end')
        ->not->toContain('data-all-day');
});

it('un evento que solo se redimensiona también lleva los datos del gesto', function () use ($eventTag, $timed) {
    $tag = $eventTag([...$timed, 'startEditable' => false, 'durationEditable' => true], ['interaction.editable' => true]);

    expect($tag)->toContain('data-resizable')
        ->not->toContain('data-movable')
        ->toContain('data-kore-event="1"')->toContain('data-kore-instance="1:')
        ->toContain('data-title=')->toContain('data-start=')->toContain('data-end=');
});

it('un evento con url y movible conserva sus atributos; sin movimiento ni clic, no', function () use ($eventTag, $timed) {
    $link = [...$timed, 'url' => 'https://example.test/1'];

    expect($eventTag($link, ['interaction.editable' => true]))->toContain('href="https://example.test/1"')->toContain('data-kore-instance=')
        // Con url el clic del calendario no aplica: sin edición no hay nada que leer.
        ->and($eventTag($link, ['interaction.event_click' => true]))->not->toContain('data-kore-instance');
});

it('en la vista mes, el punto de un evento con hora movible lleva los mismos datos', function () use ($eventTag, $timed) {
    $tag = $eventTag($timed, ['interaction.editable' => true], 'month');

    expect($tag)->toContain('kore-calendar-event-dot')->toContain('data-movable')->toContain('data-title=')->not->toContain('data-resizable');
});

// ── Rejilla horaria: las líneas de las ranuras son un nodo por columna ──

it('cada columna de la rejilla horaria lleva UN div de líneas, no uno por ranura', function () {
    $resources = new ResourceSet(array_map(fn ($i) => ['id' => "r{$i}", 'title' => "Recurso {$i}"], range(1, 10)));
    $calendar = CalendarRenderer::render(CalendarSettings::make(['view' => 'resource-week', 'date' => '2026-10-14', 'timezone' => 'UTC']), [], $resources);
    $html = (string) test()->blade('<x-kore::calendar.time-grid :calendar="$calendar" />', ['calendar' => $calendar]);

    // 10 recursos × 7 días = 70 columnas, 48 ranuras de media hora cada una.
    expect(substr_count($html, 'data-kore-drop="time"'))->toBe(70)
        ->and(substr_count($html, 'class="kore-calendar-slots"'))->toBe(70)
        ->and($html)->toContain('--kc-slots: 48; --kc-hour: 2')
        // Ya no hay un div por ranura con su borde.
        ->not->toContain('border-dashed')
        ->and(substr_count($html, 'h-[var(--kore-calendar-slot-height)]'))->toBe(48);
});

it('las ranuras de 15 minutos mandan --kc-hour: 4 y las de una hora, 1', function () {
    $render = function (string $slot) {
        $calendar = CalendarRenderer::render(CalendarSettings::make(['view' => 'week', 'date' => '2026-10-14', 'timezone' => 'UTC', 'time.slot_duration' => $slot]), []);

        return (string) test()->blade('<x-kore::calendar.time-grid :calendar="$calendar" />', ['calendar' => $calendar]);
    };

    expect($render('15m'))->toContain('--kc-slots: 96; --kc-hour: 4')
        ->and($render('1h'))->toContain('--kc-slots: 24; --kc-hour: 1');
});

it('los destinos de soltar y el horario laboral no dependen de las líneas', function () {
    $calendar = CalendarRenderer::render(CalendarSettings::make([
        'view' => 'week', 'date' => '2026-10-14', 'timezone' => 'UTC', 'business_hours' => true,
    ]), []);
    $html = (string) test()->blade('<x-kore::calendar.time-grid :calendar="$calendar" />', ['calendar' => $calendar]);

    // La celda lleva el origen, el eje y el horario: el JS calcula la ranura por coordenadas sobre ella.
    expect(substr_count($html, 'data-kore-drop="time"'))->toBe(7)
        ->and(substr_count($html, 'data-origin='))->toBe(7)
        ->and(substr_count($html, 'data-axis="86400"'))->toBe(7)
        ->and($html)->toContain('data-business=')
        ->and($html)->toContain('kore-calendar-non-business-v');
});
