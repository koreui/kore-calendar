<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use KoreUi\Calendar\KoreCalendarServiceProvider;
use KoreUi\Calendar\Tests\Feature\Fixtures\MobileViewCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\TeamCalendar;
use KoreUi\Calendar\View\CalendarViews;
use Livewire\Livewire;

/*
| Móvil (390 px): selector de vista, título, objetivos táctiles, mes y timeline estrechos y la vista
| `mobile_view`. Lo que mide de verdad —tamaños, container queries resueltas— se comprueba en un
| navegador; aquí se prueba el contrato que lo hace posible: el HTML que sale del servidor, las
| reglas del CSS y lo que llega al cliente.
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
    MobileViewCalendar::$mobile = 'day';
    MobileViewCalendar::$call = true;
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** El contenido de un archivo del paquete. */
function paquete(string $ruta): string
{
    return file_get_contents(__DIR__.'/../../'.$ruta);
}

/** El primer <select> del HTML: ['attrs' => string, 'options' => list<['value' => string, 'selected' => bool, 'href' => ?string, 'text' => string]>]. */
function selectorDeVista(string $html): ?array
{
    if (! preg_match('/<select\b([^>]*)>(.*?)<\/select>/s', $html, $s)) {
        return null;
    }

    preg_match_all('/<option\b([^>]*)>(.*?)<\/option>/s', $s[2], $opciones, PREG_SET_ORDER);

    return [
        'attrs' => $s[1],
        'options' => array_map(fn ($o) => [
            'value' => preg_match('/\bvalue="([^"]*)"/', $o[1], $m) ? $m[1] : null,
            'selected' => (bool) preg_match('/\bselected\b/', $o[1]),
            'href' => preg_match('/\bdata-href="([^"]*)"/', $o[1], $m) ? html_entity_decode($m[1]) : null,
            'text' => trim($o[2]),
        ], $opciones),
    ];
}

/** Lo que `KoreCalendarMobileView(...)` recibe, decodificado, o null si el calendario no lo monta. */
function mobileViewConfig(string $html): ?array
{
    if (! preg_match('/x-data="KoreCalendarMobileView\(JSON\.parse\(\'(.*?)\'\)\)"/s', $html, $m)) {
        return null;
    }

    // Js::from escapa con \uXXXX y Blade escapa las comillas del atributo: se deshace en ese orden.
    $json = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($u) => mb_chr(hexdec($u[1])), html_entity_decode($m[1]));

    return json_decode($json, true);
}

// ── 1. Selector de vista ───────────────────────────────────────────────

it('en modo enlaces hay un select nativo con las mismas vistas que los botones y su etiqueta del idioma', function () {
    $html = (string) $this->blade('<x-kore::calendar :resources="[[\'id\' => \'a\', \'title\' => \'Sala\']]" timezone="UTC" />');
    $select = selectorDeVista($html);

    preg_match_all('/<a\b[^>]*href="[^"]*[?&;]view=([a-z-]+)"/', $html, $botones);
    $botones = array_values(array_unique($botones[1]));

    expect($select)->not->toBeNull()
        ->and($select['attrs'])->toContain('aria-label="Vista"')
        ->and(array_column($select['options'], 'value'))->toBe(config('kore-calendar.views'))
        ->and(array_column($select['options'], 'value'))->toBe($botones)
        ->and(array_column($select['options'], 'text'))->toContain('Mes', 'Agenda', 'Timeline · día')
        // Tres vistas por recurso y dos de timeline, si hay recursos: las nueve.
        ->and($select['options'])->toHaveCount(9);
});

it('el select del modo enlaces navega a la misma URL que el botón, con el resto de la consulta conservado', function () {
    request()->merge(['view' => 'week', 'date' => '2026-11-03', 'filtro' => 'a b']);

    $html = (string) $this->blade('<x-kore::calendar timezone="UTC" />');
    $select = selectorDeVista($html);
    $porVista = array_column($select['options'], 'href', 'value');

    expect($select['attrs'])->toContain('x-on:change')->not->toContain('wire:change')
        ->and($select['options'][array_search('week', array_column($select['options'], 'value'))]['selected'])->toBeTrue()
        ->and($porVista['list'])->toContain('view=list')->toContain('date=2026-11-03')->toContain('filtro=a%20b')
        // Es la misma URL que lleva el botón de esa vista.
        ->and($html)->toContain(htmlspecialchars($porVista['list'], ENT_QUOTES));
});

it('marca como seleccionada solo la vista activa', function () {
    $select = selectorDeVista((string) $this->blade('<x-kore::calendar view="list" timezone="UTC" />'));

    expect(array_column(array_filter($select['options'], fn ($o) => $o['selected']), 'value'))->toBe(['list']);
});

it('en modo Livewire el select llama a changeView, con las vistas de ese calendario', function () {
    $html = Livewire::test(TeamCalendar::class)->html();
    $select = selectorDeVista($html);

    expect($select['attrs'])->toContain('wire:change="changeView($event.target.value)"')->not->toContain('x-on:change')
        ->and(array_column($select['options'], 'value'))->toBe(['month', 'week', 'list', 'resource-day'])
        ->and(array_column($select['options'], 'href'))->toBe([null, null, null, null])
        ->and($select['attrs'])->toContain('aria-label="Vista"');
});

it('en modo Livewire cambiar la vista mueve la opción seleccionada', function () {
    Livewire::test(TeamCalendar::class)
        ->assertSeeHtml('<option value="month" selected')
        ->call('changeView', 'list')
        ->assertSeeHtml('<option value="list" selected')
        ->assertDontSeeHtml('<option value="month" selected');
});

it('los botones y el select están los dos en el DOM; el contenedor decide cuál se ve', function () {
    $html = (string) $this->blade('<x-kore::calendar timezone="UTC" />');

    // La barra es su propio contenedor; los botones solo desde @2xl y el select hasta @2xl.
    expect($html)->toContain('class="@container"')
        ->and($html)->toMatch('/<div role="group" aria-label="Vista" class="hidden [^"]*@2xl:flex"/')
        ->and($html)->toMatch('/<div class="[^"]*@2xl:hidden">\s*<select/');
});

it('con una sola vista no hay ni select ni grupo de vistas', function () {
    $html = (string) $this->blade('<x-kore::calendar :views="[\'month\']" timezone="UTC" />');

    expect(selectorDeVista($html))->toBeNull()->and($html)->not->toContain('role="group"');
});

it('el idioma del calendario llega a la etiqueta y a las opciones del select', function () {
    $select = selectorDeVista((string) $this->blade('<x-kore::calendar locale="en" timezone="UTC" />'));

    expect($select['attrs'])->toContain('aria-label="View"')->and(array_column($select['options'], 'text'))->toContain('Month', 'Agenda');
});

// ── 2. Título ──────────────────────────────────────────────────────────

it('el título nunca se trunca: hace wrap, con o sin selector de vista', function () {
    foreach (['<x-kore::calendar timezone="UTC" />', '<x-kore::calendar :views="[\'month\']" timezone="UTC" />'] as $etiqueta) {
        preg_match('/<h2\b[^>]*>/s', (string) $this->blade($etiqueta), $h2);

        expect($h2[0])->toContain('aria-live="polite"')->toContain('break-words')->not->toContain('truncate');
    }
});

it('en estrecho el título va en su propia línea, encima de los controles', function () {
    preg_match('/<h2\b[^>]*>/s', (string) $this->blade('<x-kore::calendar timezone="UTC" />'), $h2);

    expect($h2[0])->toContain('order-first')->toContain('basis-full')->toContain('@2xl:order-none')->toContain('@2xl:basis-0');
});

// ── 3. Objetivos táctiles ──────────────────────────────────────────────

it('los botones de la barra, el select y el chevron del timeline miden de verdad lo que dice el CSS', function () {
    $barra = (string) $this->blade('<x-kore::calendar timezone="UTC" />');
    $timeline = (string) $this->blade('<x-kore::calendar view="timeline-day" :resources="[[\'id\' => \'a\', \'title\' => \'Sala\', \'group\' => \'Planta 1\']]" timezone="UTC" />');

    // 3 de navegación + 4 vistas (modo enlaces), todos con la clase; y el select.
    expect(substr_count($barra, 'kore-calendar-target'))->toBe(3 + 4 + 1)
        ->and($timeline)->toMatch('/<button\b[^>]*aria-label="Mostrar u ocultar Planta 1"[^>]*class="kore-calendar-target /s');
});

it('el «+N más» y el número del día llevan el área táctil ampliada, y su cierre mide 24 px', function () {
    $mes = (string) $this->blade('<x-kore::calendar view="month" :max-events="1" :events="$e" timezone="UTC" />', ['e' => [
        ['id' => 1, 'title' => 'A', 'start' => '2026-10-14 09:00', 'end' => '2026-10-14 10:00'],
        ['id' => 2, 'title' => 'B', 'start' => '2026-10-14 11:00', 'end' => '2026-10-14 12:00'],
    ]]);
    $semana = (string) $this->blade('<x-kore::calendar view="week" timezone="UTC" />');

    expect($mes)->toMatch('/<button\b[^>]*aria-haspopup="dialog"[^>]*class="kore-calendar-hit /s')
        ->and($mes)->toMatch('/<div\b[^>]*data-kore-calendar-cell[^>]*class="kore-calendar-hit /s')
        // El botón de cerrar de la ventana mide 24 px de verdad (no es un ::after).
        ->and($mes)->toMatch('/<button\b[^<]*aria-label="Cerrar"[^<]*class="kore-calendar-target /s')
        ->and($semana)->toContain('kore-calendar-hit truncate');
});

it('el asa de redimensionar es una zona de 24 px con su franja visible, no un h-1.5', function () {
    Livewire::withQueryParams(['view' => 'week', 'date' => '2026-10-14'])
        ->test(\KoreUi\Calendar\Tests\Feature\Fixtures\InteractiveCalendar::class)
        ->assertSeeHtml('kore-calendar-resize absolute kore-calendar-resize-y')
        ->assertDontSeeHtml('h-1.5 cursor-ns-resize');

    $css = paquete('resources/css/kore-calendar.css');

    expect($css)->toContain('.kore-calendar-resize-y {')->toContain('.kore-calendar-resize::before');
});

it('el CSS define el suelo de 24 px y el área de 44 px con un puntero grueso', function () {
    $css = paquete('resources/css/kore-calendar.css');

    expect($css)->toContain('--kore-calendar-target-min: 1.5rem;')
        ->toContain('--kore-calendar-target-coarse: 2.75rem;')
        ->toMatch('/\.kore-calendar-target,\s*\.kore-calendar-hit \{\s*min-width: var\(--kore-calendar-target-min\);\s*min-height: var\(--kore-calendar-target-min\);/')
        ->toMatch('/@media \(pointer: coarse\) \{\s*\.kore-calendar-target \{\s*min-width: var\(--kore-calendar-target-coarse\)/')
        ->toContain('.kore-calendar-hit::after')
        // Una fila de evento es un objetivo: 24 px.
        ->toContain('--kore-calendar-event-height: 1.5rem;');
});

// ── 4. Mes estrecho ────────────────────────────────────────────────────

it('un evento con hora del mes lleva la hora y el título en spans propios, y siguen en el DOM', function () {
    $html = (string) $this->blade('<x-kore::calendar view="month" :events="$e" timezone="UTC" />', ['e' => [
        ['id' => 1, 'title' => 'Reunión de equipo', 'start' => '2026-10-14 09:30', 'end' => '2026-10-14 10:30'],
    ]]);

    // Recortados por CSS como un sr-only, no quitados: el lector de pantalla los sigue leyendo.
    expect($html)->toContain('kore-calendar-event-dot')
        ->toContain('<span class="kore-calendar-event-time shrink-0')
        ->toContain('<span class="kore-calendar-event-title truncate font-medium">Reunión de equipo</span>')
        ->toContain('kore-calendar-dot')
        ->toContain('9:30');
});

it('una barra de varios días pierde solo la hora, no el título', function () {
    $html = (string) $this->blade('<x-kore::calendar view="month" :events="$e" timezone="UTC" />', ['e' => [
        ['id' => 1, 'title' => 'Viaje', 'start' => '2026-10-14 22:00', 'end' => '2026-10-16 08:00'],
    ]]);

    expect($html)->toContain('<span class="kore-calendar-event-time tabular-nums">')
        ->toContain('<span class="kore-calendar-event-title font-medium">Viaje</span>')
        ->not->toContain('kore-calendar-event-dot');
});

it('el CSS del mes: la rejilla es un contenedor y por debajo de 40 rem solo se recorta el texto, no se oculta', function () {
    $css = paquete('resources/css/kore-calendar.css');

    expect($css)->toContain('container: kore-calendar-month / inline-size;')
        ->toContain('@container kore-calendar-month (max-width: 40rem)')
        // Solo lo que está en una celda: la ventana del «+N más» conserva el texto.
        ->toContain('.kore-calendar-month-slot .kore-calendar-event-time')
        ->toContain('.kore-calendar-month-slot .kore-calendar-event-dot .kore-calendar-event-title')
        ->toContain('clip-path: inset(50%)');

    // El bloque que recorta no usa display: none (sacaría el texto del árbol de accesibilidad).
    preg_match('/@container kore-calendar-month[^{]*\{(.*?)\n\}/s', $css, $bloque);

    expect($bloque[1])->not->toContain('display: none')->not->toContain('visibility: hidden');
});

it('la rejilla del mes lleva la clase que el CSS convierte en contenedor, y la ventana «+N más» está dentro de ella', function () {
    $html = (string) $this->blade('<x-kore::calendar view="month" :max-events="1" :events="$e" timezone="UTC" />', ['e' => [
        ['id' => 1, 'title' => 'A', 'start' => '2026-10-14 09:00', 'end' => '2026-10-14 10:00'],
        ['id' => 2, 'title' => 'B', 'start' => '2026-10-14 11:00', 'end' => '2026-10-14 12:00'],
    ]]);

    expect($html)->toMatch('/<div\b[^>]*role="table"[^>]*class="kore-calendar-month /s');

    // En la ventana el evento NO es hijo de un .kore-calendar-month-slot: conserva hora y título.
    preg_match('/role="dialog".*$/s', $html, $ventana);

    expect($ventana[0])->toContain('kore-calendar-event-title')->not->toContain('kore-calendar-month-slot');
});

// ── 6. Timeline estrecho ───────────────────────────────────────────────

it('la columna de recursos sale de --kc-resource-col, que el CSS reduce por debajo de 40 rem', function () {
    $html = (string) $this->blade('<x-kore::calendar view="timeline-day" :resources="$r" timezone="UTC" />', ['r' => [
        ['id' => 'a', 'title' => 'Sala Ámbar con un nombre larguísimo', 'group' => 'Planta 1'],
    ]]);
    $css = paquete('resources/css/kore-calendar.css');

    expect($html)->toContain('grid-template-columns: var(--kc-resource-col) minmax(')
        ->not->toContain('var(--kore-calendar-resource-width)')
        // Nombre truncado con el texto entero en el title.
        ->toContain('<span class="min-w-0 truncate" title="Sala Ámbar con un nombre larguísimo">Sala Ámbar con un nombre larguísimo</span>')
        ->toContain('<span class="min-w-0 truncate" title="Planta 1">Planta 1</span>')
        ->and($css)->toContain('container: kore-calendar-timeline / inline-size;')
        ->toContain('--kore-calendar-resource-width: 12rem;')
        ->toContain('--kore-calendar-resource-width-narrow: 7rem;')
        ->toContain('--kc-resource-col: var(--kore-calendar-resource-width);')
        ->toContain('@container kore-calendar-timeline (max-width: 40rem)')
        ->toContain('--kc-resource-col: var(--kore-calendar-resource-width-narrow);');
});

it('los contenedores llevan w-full: uno no toma su ancho del contenido y en un flex se quedaba en 0', function () {
    $mes = (string) $this->blade('<x-kore::calendar view="month" timezone="UTC" />');
    $timeline = (string) $this->blade('<x-kore::calendar view="timeline-day" :resources="[[\'id\' => \'a\', \'title\' => \'Sala\']]" timezone="UTC" />');

    expect($mes)->toContain('class="kore-calendar w-full space-y-3"')
        ->toMatch('/class="kore-calendar-month w-full /')
        ->and($timeline)->toMatch('/class="kore-calendar-timeline w-full /');
});

it('cada @container nombrado del CSS tiene su contenedor declarado', function () {
    $css = paquete('resources/css/kore-calendar.css');

    preg_match_all('/@container ([\w-]+) \(/', $css, $usados);

    expect($usados[1])->not->toBeEmpty();

    foreach (array_unique($usados[1]) as $nombre) {
        expect($css)->toContain("container: {$nombre} / inline-size;");
    }
});

// ── 5. mobile_view ─────────────────────────────────────────────────────

it('mobile_view llega al cliente con la vista, la actual, el parámetro y el modo', function () {
    $html = (string) $this->blade('<x-kore::calendar mobile-view="day" timezone="UTC" />');

    expect(mobileViewConfig($html))->toBe(['view' => 'day', 'current' => 'month', 'param' => 'view', 'live' => false]);
});

it('el componente de Alpine del cliente va en el bundle, y la página lo carga con @assets', function () {
    expect(paquete('resources/js/index.js'))->toContain('KoreCalendarMobileView')
        ->and(paquete('dist/kore-calendar.js'))->toContain('KoreCalendarMobileView')
        // Sin el @assets, en la agenda (que no lo declara) el x-data no encontraría su componente.
        ->and(paquete('resources/views/components/calendar/index.blade.php'))->toContain('@assets');
});

it('en la agenda, que no declara el bundle, mobile_view lo carga él: el x-data no se queda sin componente', function () {
    // @assets se recoge al servir una página completa (no en un fragmento de $this->blade()).
    Route::get('/_mobile-view', fn () => Blade::render('<html><head></head><body><x-kore::calendar view="list" :mobile-view="$mobile" timezone="UTC" /></body></html>', ['mobile' => request('m') ?: null]));

    // Livewire guarda los assets ya vistos en estáticos (los de otros tests y los de la petición anterior).
    Livewire::flushState();
    $sin = $this->get('/_mobile-view')->getContent();
    Livewire::flushState();
    $con = $this->get('/_mobile-view?m=day')->getContent();

    expect($con)->toContain('<script src="'.KoreCalendarServiceProvider::scriptUrl().'" defer></script>')
        ->and($sin)->not->toContain('kore-calendar.js');
});

it('sin mobile_view no se monta nada', function () {
    expect(mobileViewConfig((string) $this->blade('<x-kore::calendar timezone="UTC" />')))->toBeNull()
        ->and(config('kore-calendar.mobile_view'))->toBeNull();
});

it('mobile_view del config funciona y la prop gana, también apagándola con false', function () {
    config(['kore-calendar.mobile_view' => 'list']);

    expect(mobileViewConfig((string) $this->blade('<x-kore::calendar timezone="UTC" />'))['view'])->toBe('list')
        ->and(mobileViewConfig((string) $this->blade('<x-kore::calendar mobile-view="day" timezone="UTC" />'))['view'])->toBe('day')
        ->and(mobileViewConfig((string) $this->blade('<x-kore::calendar :mobile-view="false" timezone="UTC" />')))->toBeNull();
});

it('mobile_view se valida contra las vistas disponibles', function () {
    // Inventada, fuera de views, y de recursos sin recursos: ninguna llega al cliente.
    foreach (['inventada', 'timeline-day', 'resource-week'] as $mala) {
        expect(mobileViewConfig((string) $this->blade('<x-kore::calendar mobile-view="'.$mala.'" timezone="UTC" />')))->toBeNull();
    }

    expect(mobileViewConfig((string) $this->blade('<x-kore::calendar mobile-view="day" :views="[\'month\', \'list\']" timezone="UTC" />')))->toBeNull();

    // De recursos con recursos, sí.
    $conRecursos = (string) $this->blade('<x-kore::calendar mobile-view="resource-day" :resources="[[\'id\' => \'a\', \'title\' => \'Sala\']]" timezone="UTC" />');

    expect(mobileViewConfig($conRecursos)['view'])->toBe('resource-day');
});

it('mobile_view sin barra no se monta: la URL se ignora y no habría a qué cambiar', function () {
    expect(mobileViewConfig((string) $this->blade('<x-kore::calendar mobile-view="day" :toolbar="false" timezone="UTC" />')))->toBeNull();
});

it('mobile_view usa el nombre de parámetro configurado y manda la vista de la URL como la actual', function () {
    request()->merge(['vista' => 'week']);

    $html = (string) $this->blade('<x-kore::calendar mobile-view="day" view-param="vista" timezone="UTC" />');

    expect(mobileViewConfig($html))->toBe(['view' => 'day', 'current' => 'week', 'param' => 'vista', 'live' => false]);
});

it('mobile_view en KoreCalendar: ->mobileView() llega al cliente en modo Livewire', function () {
    $html = Livewire::test(MobileViewCalendar::class)->html();

    expect(mobileViewConfig($html))->toBe(['view' => 'day', 'current' => 'month', 'param' => 'view', 'live' => true]);
});

it('KoreCalendar valida mobileView() contra sus vistas, y null apaga la del config', function () {
    MobileViewCalendar::$mobile = 'timeline-day';
    expect(mobileViewConfig(Livewire::test(MobileViewCalendar::class)->html()))->toBeNull();

    MobileViewCalendar::$mobile = 'inventada';
    expect(mobileViewConfig(Livewire::test(MobileViewCalendar::class)->html()))->toBeNull();

    config(['kore-calendar.mobile_view' => 'list']);

    // Sin llamar a mobileView(), manda el config.
    MobileViewCalendar::$call = false;
    expect(mobileViewConfig(Livewire::test(MobileViewCalendar::class)->html())['view'])->toBe('list');

    // Con mobileView(null), no.
    MobileViewCalendar::$call = true;
    MobileViewCalendar::$mobile = null;
    expect(mobileViewConfig(Livewire::test(MobileViewCalendar::class)->html()))->toBeNull();
});

it('mobile_view no cambia la vista que pinta el servidor: la primera carga sigue siendo la normal', function () {
    Livewire::test(MobileViewCalendar::class)->assertSet('view', '')->assertSee('Octubre 2026');

    // Y con ?view= en la URL el servidor pinta esa, y el cliente la respeta (no cambia nada).
    $html = Livewire::withQueryParams(['view' => 'list'])->test(MobileViewCalendar::class)->html();

    expect(mobileViewConfig($html)['current'])->toBe('list');
});

it('CalendarViews::mobile() solo acepta una vista de las disponibles', function () {
    expect(CalendarViews::mobile('day', ['month', 'day']))->toBe('day')
        ->and(CalendarViews::mobile('day', ['month']))->toBeNull()
        ->and(CalendarViews::mobile(null, ['month']))->toBeNull()
        ->and(CalendarViews::mobile(false, ['month']))->toBeNull()
        ->and(CalendarViews::mobile(['day'], ['day']))->toBeNull()
        ->and(CalendarViews::mobile('', ['day']))->toBeNull();
});

it('el config de serie trae mobile_view en null', function () {
    expect(array_key_exists('mobile_view', config('kore-calendar')))->toBeTrue()
        ->and(config('kore-calendar.mobile_view'))->toBeNull();
});
