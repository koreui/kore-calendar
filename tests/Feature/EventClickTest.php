<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Tests\Feature\Fixtures\ClickCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\FormCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\InteractiveCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\PlainCalendar;
use KoreUi\Calendar\View\CalendarSettings;
use Livewire\Livewire;

/*
| Pulsar un evento: `clickEvent()` y el hook `onEventClick()`.
| La semana de los tests es la del lunes 12 al domingo 18 de octubre de 2026, en
| Europe/Madrid (los eventos de siempre, InteractiveCalendar::defaultEvents):
|   1 «Revisión»  mié 14, 10:00-11:30
|   2 «Congreso»  jue 15 → vie 16 (día completo, [15, 17))
|   3 «Bloqueo»   vie 16, 15:00-16:00
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
    FormCalendar::fresh();
    ClickCalendar::$clicked = [];
    ClickCalendar::$eventClick = null;
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Los params del primer evento de Livewire con ese nombre, o null. */
function ecDispatched($testable, string $name): ?array
{
    return collect(data_get($testable->effects, 'dispatches'))->firstWhere('name', $name)['params'] ?? null;
}

// ── Ajuste ─────────────────────────────────────────────────────────────

it('event_click viene apagado de fábrica y se lee con la cascada de siempre', function () {
    expect(CalendarSettings::make()->eventClick)->toBeFalse();

    config(['kore-calendar.interaction.event_click' => true]);

    expect(CalendarSettings::make()->eventClick)->toBeTrue()
        ->and(CalendarSettings::make(['interaction.event_click' => false])->eventClick)->toBeFalse();
});

// ── Pintar ─────────────────────────────────────────────────────────────

it('un calendario de solo lectura sin clic no pinta eventos enfocables', function () {
    Livewire::test(PlainCalendar::class)
        ->assertDontSeeHtml('data-clickable')
        ->assertDontSeeHtml('role="button"');
});

it('con eventForm() los eventos son enfocables aunque el calendario no sea editable', function () {
    $html = Livewire::test(FormCalendar::class)->html();

    expect($html)->toContain('data-clickable')
        ->and($html)->toContain('role="button"')
        ->and($html)->toContain('tabindex="0"')
        // No es editable: nada que arrastrar ni redimensionar.
        ->and($html)->not->toContain('data-movable')
        ->and($html)->not->toContain('data-resizable')
        // Ni la ayuda de «Espacio para mover», que no aplica a un evento que no se mueve.
        ->and($html)->not->toContain('aria-describedby="kore-calendar-kbd-help');
});

it('un evento movible y pulsable lleva los dos atributos y una sola parada de tabulación', function () {
    FormCalendar::$undoable = true;   // esto lo hace editable()

    $html = Livewire::test(FormCalendar::class)->html();

    expect($html)->toContain('data-movable')
        ->and($html)->toContain('data-clickable')
        ->and(substr_count($html, 'data-kore-event="1"'))->toBeGreaterThanOrEqual(1);

    // Ninguna etiqueta de evento repite el tabindex.
    preg_match_all('/<div[^>]*data-kore-event="1"[^>]*>/', $html, $etiquetas);
    foreach ($etiquetas[0] as $etiqueta) {
        expect(substr_count($etiqueta, 'tabindex'))->toBe(1);
    }
});

it('un evento con url sigue siendo un enlace: no es pulsable', function () {
    FormCalendar::$events = [
        ['id' => 1, 'title' => 'Enlace', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'url' => 'https://example.test/1'],
    ];

    $html = Livewire::test(FormCalendar::class)->html();

    expect($html)->toContain('href="https://example.test/1"')
        ->and($html)->not->toContain('data-clickable')
        ->and($html)->not->toContain('role="button"');
});

it('sobrescribir onEventClick() basta para que los eventos sean pulsables, sin formulario', function () {
    $html = Livewire::test(ClickCalendar::class)->html();

    expect($html)->toContain('data-clickable')->and($html)->toContain('tabindex="0"');
});

it('eventClick(false) apaga el clic aunque haya formulario o hook propio', function () {
    FormCalendar::$config = ['interaction.event_click' => false];
    expect(Livewire::test(FormCalendar::class)->html())->not->toContain('data-clickable');

    ClickCalendar::$eventClick = false;
    expect(Livewire::test(ClickCalendar::class)->html())->not->toContain('data-clickable');
});

// ── clickEvent: abre el formulario de edición ──────────────────────────

it('pulsar un evento abre el formulario de edición con el evento cargado', function () {
    $t = Livewire::test(FormCalendar::class)->call('clickEvent', 1)->assertDispatched('kore:open');

    $abrir = ecDispatched($t, 'kore:open');

    expect($abrir['name'])->toBe('kore-calendar-event-form')
        ->and($abrir['overlayAttributes'])->toBe(['title' => 'Editar evento'])
        ->and($abrir['arguments'])->toBe([
            'eventId' => 1,
            'title' => 'Revisión',
            'allDay' => false,
            'start' => '2026-10-14 10:00:00',
            'end' => '2026-10-14 11:30:00',
            'resourceId' => null,
            'color' => null,
            'mode' => 'edit',
            'calendar' => $t->id(),
            'locale' => 'es',
            'firstDay' => 1,
            'resources' => [],
        ]);
});

it('abrir el formulario no repinta el calendario: nada cambió', function () {
    $t = Livewire::test(FormCalendar::class)->call('clickEvent', 1);

    expect(data_get($t->effects, 'html'))->toBeNull();
});

it('un evento de día completo llega con las fechas y el fin exclusivo', function () {
    $t = Livewire::test(FormCalendar::class)->call('clickEvent', 2);

    expect(ecDispatched($t, 'kore:open')['arguments'])->toMatchArray([
        'eventId' => 2, 'title' => 'Congreso', 'allDay' => true, 'start' => '2026-10-15', 'end' => '2026-10-17',
    ]);
});

it('un evento sin fin llega sin fin: el formulario no enseña el de por defecto', function () {
    FormCalendar::$events = [['id' => 5, 'title' => 'Sin fin', 'start' => '2026-10-14 10:00']];

    $t = Livewire::test(FormCalendar::class)->call('clickEvent', 5);

    expect(ecDispatched($t, 'kore:open')['arguments'])->toMatchArray(['start' => '2026-10-14 10:00:00', 'end' => null]);
});

it('lleva el color, el recurso y la lista de recursos del calendario', function () {
    FormCalendar::$resources = [['id' => 10, 'title' => 'Sala A'], ['id' => 'b', 'title' => 'Sala B']];
    FormCalendar::$events = [
        ['id' => 5, 'title' => 'Reunión', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'resourceIds' => ['b', 10], 'color' => 'warning'],
    ];

    $t = Livewire::test(FormCalendar::class)->call('clickEvent', 5);
    $args = ecDispatched($t, 'kore:open')['arguments'];

    expect($args['resourceId'])->toBe('b')
        ->and($args['color'])->toBe('warning')
        ->and($args['resources'])->toBe([['value' => '10', 'label' => 'Sala A'], ['value' => 'b', 'label' => 'Sala B']]);
});

it('el id llega por JSON como texto o como número: es el mismo evento', function () {
    $t = Livewire::test(FormCalendar::class)->call('clickEvent', '1')->assertDispatched('kore:open');

    expect(ecDispatched($t, 'kore:open')['arguments']['eventId'])->toBe(1);
});

it('el formulario y el calendario hablan el mismo idioma y la misma semana', function () {
    FormCalendar::$config = ['locale' => 'en', 'first_day' => 0];

    $t = Livewire::test(FormCalendar::class)->call('clickEvent', 1);

    expect(ecDispatched($t, 'kore:open')['arguments'])->toMatchArray(['locale' => 'en', 'firstDay' => 0]);
});

// ── clickEvent: lo que no abre nada ────────────────────────────────────

it('un id que no existe no abre nada y avisa', function () {
    $t = Livewire::test(FormCalendar::class)->call('clickEvent', 999)->assertNotDispatched('kore:open');

    $toast = ecDispatched($t, 'kore:toast')['toast'];

    expect($toast)->toMatchArray(['type' => 'error', 'title' => 'El evento ya no está disponible'])
        // La vista está desfasada: se repinta para que desaparezca.
        ->and(data_get($t->effects, 'html'))->not->toBeNull();
});

it('IDOR: un evento que events() no devuelve para esta vista no se puede abrir', function () {
    // El usuario solo ve el 1 (su scope). El 77 existe en la base de datos de otro.
    FormCalendar::$events = [InteractiveCalendar::defaultEvents()[0]];

    Livewire::test(FormCalendar::class)->call('clickEvent', 77)->assertNotDispatched('kore:open');

    // Y uno de otra semana no se abre desde esta vista.
    FormCalendar::$events = [
        ...InteractiveCalendar::defaultEvents(),
        ['id' => 55, 'title' => 'Otra semana', 'start' => '2026-11-20 10:00', 'end' => '2026-11-20 11:00'],
    ];

    Livewire::test(FormCalendar::class)->call('clickEvent', 55)->assertNotDispatched('kore:open');
    Livewire::test(FormCalendar::class)->set('date', '2026-11-20')->call('clickEvent', 55)->assertDispatched('kore:open');
});

it('un evento editable:false no abre el formulario', function () {
    FormCalendar::$events = [['id' => 1, 'title' => 'Festivo', 'start' => '2026-10-14', 'editable' => false]];

    Livewire::test(FormCalendar::class)->call('clickEvent', 1)->assertNotDispatched('kore:open');
});

it('un calendario sin clic no explica nada: el cliente no ofrece el gesto', function () {
    $t = Livewire::test(PlainCalendar::class)->call('clickEvent', 1);

    expect(data_get($t->effects, 'dispatches'))->toBeNull()
        ->and(data_get($t->effects, 'html'))->toBeNull();
});

it('con el clic apagado explícitamente, clickEvent no hace nada aunque haya formulario', function () {
    FormCalendar::$config = ['interaction.event_click' => false];

    Livewire::test(FormCalendar::class)->call('clickEvent', 1)->assertNotDispatched('kore:open');
});

// ── onEventClick sobrescrito ───────────────────────────────────────────

it('el hook del consumidor recibe el evento ya resuelto', function () {
    Livewire::test(ClickCalendar::class)->call('clickEvent', 2)->assertNotDispatched('kore:open');

    expect(ClickCalendar::$clicked)->toHaveCount(1)
        ->and(ClickCalendar::$clicked[0]->id)->toBe(2)
        ->and(ClickCalendar::$clicked[0]->title)->toBe('Congreso');
});

it('el hook del consumidor tampoco recibe un id ajeno', function () {
    Livewire::test(ClickCalendar::class)->call('clickEvent', 999);

    expect(ClickCalendar::$clicked)->toBe([]);
});

// ── Seleccionar abre el formulario de creación ─────────────────────────

function ecTs(string $wall): int
{
    return KoreUi\Calendar\Time\Marker::parse($wall)->ts;
}

it('seleccionar un rango abre el formulario de un evento nuevo con ese rango', function () {
    FormCalendar::$config = ['interaction.selectable' => true];

    $t = Livewire::test(FormCalendar::class)->call('selectRange', ecTs('2026-10-14 14:00'), ecTs('2026-10-14 15:30'), false);
    $abrir = ecDispatched($t, 'kore:open');

    expect($abrir['name'])->toBe('kore-calendar-event-form')
        ->and($abrir['overlayAttributes'])->toBe(['title' => 'Nuevo evento'])
        ->and($abrir['arguments'])->toBe([
            'allDay' => false,
            'start' => '2026-10-14 14:00:00',
            'end' => '2026-10-14 15:30:00',
            'resourceId' => null,
            'mode' => 'create',
            'calendar' => $t->id(),
            'locale' => 'es',
            'firstDay' => 1,
            'resources' => [],
        ]);
});

it('un rango de días completos abre el formulario con el fin exclusivo', function () {
    FormCalendar::$config = ['interaction.selectable' => true];

    $t = Livewire::test(FormCalendar::class)->call('selectRange', ecTs('2026-10-17'), ecTs('2026-10-19'), true);

    expect(ecDispatched($t, 'kore:open')['arguments'])->toMatchArray(['allDay' => true, 'start' => '2026-10-17', 'end' => '2026-10-19']);
});

it('el recurso donde se soltó llega al formulario', function () {
    FormCalendar::$config = ['interaction.selectable' => true];
    FormCalendar::$resources = [['id' => 10, 'title' => 'Sala A'], ['id' => 20, 'title' => 'Sala B']];

    $t = Livewire::test(FormCalendar::class)
        ->call('changeView', 'resource-week')
        ->call('selectRange', ecTs('2026-10-14 14:00'), ecTs('2026-10-14 15:00'), false, 20);

    expect(ecDispatched($t, 'kore:open')['arguments']['resourceId'])->toBe(20);
});

it('una selección fuera del rango válido no abre nada y avisa', function () {
    FormCalendar::$config = ['interaction.selectable' => true, 'interaction.valid_range' => ['start' => '2026-10-20', 'end' => null]];

    $t = Livewire::test(FormCalendar::class)
        ->call('selectRange', ecTs('2026-10-14 14:00'), ecTs('2026-10-14 15:00'), false)
        ->assertNotDispatched('kore:open');

    expect(ecDispatched($t, 'kore:toast')['toast']['title'])->toBe('Fuera del rango permitido');
});

it('sin eventForm() seleccionar no abre ningún formulario', function () {
    FormCalendar::$form = false;
    FormCalendar::$config = ['interaction.selectable' => true];

    Livewire::test(FormCalendar::class)
        ->call('selectRange', ecTs('2026-10-14 14:00'), ecTs('2026-10-14 15:00'), false)
        ->assertNotDispatched('kore:open');
});
