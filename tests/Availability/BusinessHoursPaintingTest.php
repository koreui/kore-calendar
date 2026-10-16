<?php

use KoreUi\Calendar\Layout\TimeGridLayout;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\View\CalendarRenderer;
use KoreUi\Calendar\View\CalendarSettings;

/*
| El horario laboral en el HTML: lo no laborable son bloques decorativos en % del eje,
| calculados por el layout. Semana del lunes 12 al domingo 18 de octubre de 2026 (UTC,
| para que las posiciones sean las del reloj); el miércoles 14 es el día de las vistas de un día.
*/

/** El calendario ya renderizado a HTML. */
function bhHtml(string $view, array $settings = [], array $events = [], ?ResourceSet $resources = null): string
{
    $calendar = CalendarRenderer::render(
        CalendarSettings::make([...['view' => $view, 'date' => '2026-10-14', 'timezone' => 'UTC', 'first_day' => 1], ...$settings]),
        $events,
        $resources,
    );
    $component = match ($view) {
        'month' => 'month',
        'list' => 'list',
        'timeline-day', 'timeline-week', 'timeline-month' => 'timeline',
        default => 'time-grid',
    };

    return (string) test()->blade("<x-kore::calendar.{$component} :calendar=\"\$calendar\" />", ['calendar' => $calendar]);
}

/** Los bloques decorativos de un HTML, en orden: «top height» (rejilla horaria). */
function bhVertical(string $html): array
{
    preg_match_all('/kore-calendar-non-business-v" style="--kc-top: ([\d.]+); --kc-height: ([\d.]+)"/', $html, $m, PREG_SET_ORDER);

    return array_map(fn (array $x) => $x[1].' '.$x[2], $m);
}

/** Los de una celda concreta (columna `$i` de las de hora), separando el HTML por celdas de hora. */
function bhColumn(string $html, int $i): array
{
    $cells = preg_split('/(?=data-kore-drop="time")/', $html);

    return bhVertical($cells[$i + 1]);
}

// ── Rejilla horaria ────────────────────────────────────────────────────

it('la semana pinta lo no laborable de cada columna en % del eje', function () {
    $html = bhHtml('week', ['business_hours' => true]);

    // Lunes a viernes: de 00:00 a 09:00 (37,5 %) y de 17:00 a 24:00 (29,1667 %). Sábado y domingo, enteros.
    expect(bhColumn($html, 0))->toBe(['0 37.5', '70.8333 29.1667'])
        ->and(bhColumn($html, 4))->toBe(['0 37.5', '70.8333 29.1667'])
        ->and(bhColumn($html, 5))->toBe(['0 100'])
        ->and(bhColumn($html, 6))->toBe(['0 100'])
        ->and(count(bhVertical($html)))->toBe(12);
});

it('los fondos son decorativos: aria-hidden, sin puntero y sin texto', function () {
    $html = bhHtml('week', ['business_hours' => true]);

    preg_match_all('/<div[^>]*kore-calendar-non-business[^>]*>/', $html, $m);

    expect($m[0])->not->toBeEmpty();

    foreach ($m[0] as $etiqueta) {
        expect($etiqueta)->toContain('aria-hidden="true"');
    }

    expect($html)->not->toMatch('/kore-calendar-non-business[^>]*>[^<]+</');
});

it('sin horario no se pinta nada ni se manda ningún data-business', function (string $view) {
    $html = bhHtml($view);

    expect($html)->not->toContain('kore-calendar-non-business')
        ->and($html)->not->toContain('data-business');
})->with(['week', 'month', 'timeline-day', 'timeline-week', 'resource-day']);

it('con slot_min_time y slot_max_time, los % son del eje visible', function () {
    $html = bhHtml('week', ['business_hours' => true, 'time.slot_min_time' => '08:00', 'time.slot_max_time' => '20:00']);

    // Eje de 12 h: 08:00-09:00 = 8,3333 %, 17:00-20:00 = 25 % desde el 75 %.
    expect(bhColumn($html, 0))->toBe(['0 8.3333', '75 25']);
});

it('el eje de 26:00 incluye la madrugada del día siguiente como parte del horario de ayer', function () {
    $noche = ['daysOfWeek' => [2], 'startTime' => '22:00', 'endTime' => '26:00'];   // martes 22:00 → miércoles 02:00
    $layout = fn () => TimeGridLayout::build(
        \KoreUi\Calendar\Time\ViewRange::for('week', Marker::parse('2026-10-14'), new \KoreUi\Calendar\Time\ViewOptions(\KoreUi\Calendar\Time\WeekRules::resolve(1, 'iso', 'es'))),
        [], Marker::parse('2026-10-14 10:00'),
        new \KoreUi\Calendar\Layout\LayoutOptions(slotMin: 0, slotMax: 26 * 3600),
        businessHours: new \KoreUi\Calendar\Availability\BusinessHours($noche),
    );

    // Eje de 26 h. El martes (columna 1) abre de 22:00 a 26:00: lo no laborable es [0, 22:00) = 84,6154 %.
    // El miércoles (columna 2) abre de 00:00 a 02:00 (la cola del turno del martes): fuera queda [02:00, 26:00).
    expect($layout()->hours[1]['off'])->toBe([['top' => 0.0, 'height' => 84.6154]])
        ->and($layout()->hours[2]['off'])->toBe([['top' => 7.6923, 'height' => 92.3077]]);
});

it('cada destino de arrastre lleva sus tramos laborables en data-business', function () {
    $html = bhHtml('week', ['business_hours' => true]);
    $lunes = Marker::parse('2026-10-12')->ts;
    $sabado = Marker::parse('2026-10-17')->ts;

    // La columna del lunes: 09:00-17:00. El carril de todo el día lleva los del día; el sábado, vacío (no se trabaja).
    expect($html)->toContain('data-business="'.($lunes + 9 * 3600).':'.($lunes + 17 * 3600).'"')
        ->and($html)->toContain('data-date="'.$sabado.'"')
        ->and($html)->toMatch('/data-kore-drop="allday"\s+data-date="'.$sabado.'"\s+data-business=""/');
});

// ── Recursos ───────────────────────────────────────────────────────────

it('con recursos, cada columna usa el horario de su recurso y, si no tiene, el del calendario', function () {
    $resources = new ResourceSet([
        ['id' => 'a', 'title' => 'Sala A', 'businessHours' => ['daysOfWeek' => [3], 'startTime' => '08:00', 'endTime' => '12:00']],
        ['id' => 'b', 'title' => 'Sala B'],
        ['id' => 'c', 'title' => 'Máquina', 'businessHours' => false],
    ]);

    $html = bhHtml('resource-day', ['business_hours' => true], resources: $resources);

    // Las columnas van en el orden de los recursos (por título): Máquina, Sala A, Sala B.
    // La máquina está siempre abierta; A abre 08-12 (fuera: 0-8 y 12-24); B hereda 09-17.
    expect(bhColumn($html, 0))->toBe([])
        ->and(bhColumn($html, 1))->toBe(['0 33.3333', '50 50'])
        ->and(bhColumn($html, 2))->toBe(['0 37.5', '70.8333 29.1667'])
        // La máquina no manda restricción al cliente; las salas sí (su columna y su carril de todo el día).
        ->and(substr_count($html, 'data-kore-drop="time"'))->toBe(3)
        ->and(substr_count($html, 'data-business='))->toBe(4);
});

it('un recurso con horario y un calendario sin él: solo ese recurso se pinta', function () {
    $resources = new ResourceSet([
        ['id' => 'a', 'title' => 'Sala A', 'businessHours' => true],
        ['id' => 'b', 'title' => 'Sala B'],
    ]);

    $html = bhHtml('resource-day', resources: $resources);

    expect(bhColumn($html, 0))->toBe(['0 37.5', '70.8333 29.1667'])
        ->and(bhColumn($html, 1))->toBe([]);
});

it('el horario de un recurso mal escrito rompe al renderizar, con el id del recurso', function () {
    $resources = new ResourceSet([['id' => 'sala-9', 'title' => 'Sala 9', 'businessHours' => ['dias' => [1]]]]);

    expect(fn () => bhHtml('resource-day', resources: $resources))
        ->toThrow(InvalidArgumentException::class, 'Recurso «sala-9»');
});

// ── Timeline ───────────────────────────────────────────────────────────

it('el timeline pinta lo no laborable por fila de recurso, en % del eje', function () {
    $resources = new ResourceSet([
        ['id' => 'a', 'title' => 'Sala A', 'businessHours' => ['daysOfWeek' => [3], 'startTime' => '08:00', 'endTime' => '12:00']],
        ['id' => 'b', 'title' => 'Sala B'],
    ]);

    $html = bhHtml('timeline-day', ['business_hours' => true], resources: $resources);
    preg_match_all('/kore-calendar-non-business-h" style="--kc-left: ([\d.]+); --kc-width: ([\d.]+)"/', $html, $m, PREG_SET_ORDER);

    // Un día de 24 ranuras de 1 h. Sala A: 0-8 h y 12-24 h. Sala B: 0-9 h y 17-24 h.
    expect(array_map(fn ($x) => $x[1].' '.$x[2], $m))->toBe(['0 33.3333', '50 50', '0 37.5', '70.8333 29.1667']);
});

it('con ranuras de días solo se atenúan los días enteros sin horario, un fin de semana en un solo bloque', function () {
    $html = bhHtml('timeline-week', ['business_hours' => true], resources: new ResourceSet([['id' => 'a', 'title' => 'Sala A']]));
    preg_match_all('/kore-calendar-non-business-h" style="--kc-left: ([\d.]+); --kc-width: ([\d.]+)"/', $html, $m, PREG_SET_ORDER);

    // Lunes a viernes trabajan; sábado y domingo = 2 de 7 días = 28,5714 %, desde el 71,4286 %.
    expect(array_map(fn ($x) => $x[1].' '.$x[2], $m))->toBe(['71.4286 28.5714'])
        // Y sin data-business: con ranuras de días el gesto es de días y no hace falta la pista.
        ->and($html)->not->toContain('data-business');
});

it('el timeline sin recursos usa el horario del calendario en su única fila', function () {
    $html = bhHtml('timeline-day', ['business_hours' => true]);

    preg_match_all('/kore-calendar-non-business-h"/', $html, $m);

    expect($m[0])->toHaveCount(2);
});

// ── Mes ────────────────────────────────────────────────────────────────

it('el mes atenúa los días sin ningún tramo, y solo esos', function () {
    $html = bhHtml('month', ['business_hours' => true]);

    // Octubre 2026 en 6 semanas (de lunes 28-sep a domingo 8-nov): 12 sábados y domingos.
    expect(substr_count($html, 'kore-calendar-non-business-day'))->toBe(12);

    $sinSabados = bhHtml('month', ['business_hours' => ['daysOfWeek' => [0, 1, 2, 3, 4, 5, 6]]]);

    expect($sinSabados)->not->toContain('kore-calendar-non-business');
});

it('un día de madrugada de un turno de noche no se atenúa en el mes', function () {
    $html = bhHtml('month', ['business_hours' => ['daysOfWeek' => [5], 'startTime' => '22:00', 'endTime' => '26:00']]);

    // Solo trabajan los viernes y los sábados (la cola): de 42 días, 42 - 6 viernes - 6 sábados = 30 atenuados.
    expect(substr_count($html, 'kore-calendar-non-business-day'))->toBe(30);
});

// ── Ajustes ────────────────────────────────────────────────────────────

it('el ajuste business_hours viene del config y la prop gana', function () {
    config(['kore-calendar.business_hours' => true]);

    expect(CalendarSettings::make()->businessHours->isEmpty())->toBeFalse()
        ->and(CalendarSettings::make(['business_hours' => false])->businessHours->isEmpty())->toBeTrue()
        ->and(CalendarSettings::make(['business_hours' => ['startTime' => '08:00']])->businessHours->toArray()[0]['startTime'])->toBe('08:00');
});

it('un horario del config mal escrito falla al resolver los ajustes', function () {
    expect(fn () => CalendarSettings::make(['business_hours' => ['daysOfWeek' => [9]]]))
        ->toThrow(InvalidArgumentException::class, 'el día «9» de `daysOfWeek` no es válido');
});

it('el token del CSS está definido para claro y oscuro', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/kore-calendar.css');
    $oscuro = substr($css, strpos($css, "[data-theme='dark']"));

    expect($css)->toContain('--kore-calendar-non-business:')
        ->and(substr($oscuro, 0, 200))->toContain('--kore-calendar-non-business:');
});

it('la etiqueta <x-kore::calendar> acepta business-hours', function () {
    $html = (string) test()->blade('<x-kore::calendar view="week" date="2026-10-14" timezone="UTC" :business-hours="true" :toolbar="false" />');

    expect($html)->toContain('kore-calendar-non-business-v');
});
