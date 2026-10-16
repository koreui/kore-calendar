<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Tests\Feature\Fixtures\FormCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\HoursCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\InteractiveCalendar as Cal;
use KoreUi\Calendar\View\CalendarSettings;
use Livewire\Livewire;

require_once __DIR__.'/../Interaction/helpers.php';

/*
| Horario laboral, restricciones y conflictos de una pieza: lo que el navegador propone
| (moveEvent, resizeEvent, selectRange) y el servidor decide. La semana de siempre
| (lunes 12 a domingo 18 de octubre de 2026, Europe/Madrid) y los eventos de siempre:
|   1 «Revisión»  mié 14, 10:00-11:30
|   2 «Congreso»  jue 15 → vie 16 (día completo, [15, 17))
|   3 «Bloqueo»   vie 16, 15:00-16:00, overlap false
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
    Cal::fresh();
    Cal::$config = ['business_hours' => true, 'interaction.event_constraint' => 'businessHours'];
});

afterEach(fn () => CarbonImmutable::setTestNow());

function bhTs(string $wall): int
{
    return ixMarker($wall)->ts;
}

function bhToast($testable): ?array
{
    $toast = collect(data_get($testable->effects, 'dispatches'))->firstWhere('name', 'kore:toast');

    return $toast['params']['toast'] ?? null;
}

// ── Mover y redimensionar ──────────────────────────────────────────────

it('mover fuera del horario laboral se rechaza con el motivo y no se persiste', function (string $destino) {
    $t = Livewire::test(Cal::class)->call('moveEvent', 1, bhTs($destino), false);

    $t->assertReturned(['ok' => false, 'message' => 'Fuera del horario laboral']);

    expect(Cal::$moved)->toBe([])
        ->and(bhToast($t))->toMatchArray(['type' => 'error', 'title' => 'No se pudo mover el evento', 'description' => 'Fuera del horario laboral']);
})->with([
    'después de cerrar' => ['2026-10-14 18:00'],
    'antes de abrir' => ['2026-10-14 07:00'],
    'empieza dentro y acaba fuera (16:00 + 1 h 30 = 17:30)' => ['2026-10-14 16:00'],
    'un sábado' => ['2026-10-17 10:00'],
]);

it('mover dentro del horario se acepta, también hasta el último minuto', function (string $destino) {
    Livewire::test(Cal::class)->call('moveEvent', 1, bhTs($destino), false)->assertReturned(fn (array $r) => $r['ok'] === true);

    expect(Cal::$moved)->toHaveCount(1);
})->with([
    'otro día laborable' => ['2026-10-15 14:00'],
    'acaba justo al cerrar (15:30 + 1 h 30 = 17:00)' => ['2026-10-14 15:30'],
    'empieza justo al abrir' => ['2026-10-14 09:00'],
]);

it('sin eventConstraint el horario solo se pinta: mover fuera de horario se acepta', function () {
    Cal::$config = ['business_hours' => true];

    Livewire::test(Cal::class)->call('moveEvent', 1, bhTs('2026-10-14 20:00'), false)->assertReturned(fn (array $r) => $r['ok'] === true);

    expect(Cal::$moved)->toHaveCount(1);
});

it('con eventConstraint pero sin horario definido, todo vale', function () {
    Cal::$config = ['interaction.event_constraint' => 'businessHours'];

    Livewire::test(Cal::class)->call('moveEvent', 1, bhTs('2026-10-17 03:00'), false)->assertReturned(fn (array $r) => $r['ok'] === true);
});

it('redimensionar también respeta el horario, por el fin y por el inicio', function () {
    $t = Livewire::test(Cal::class);

    $t->call('resizeEvent', 1, bhTs('2026-10-14 17:30'), false)->assertReturned(['ok' => false, 'message' => 'Fuera del horario laboral']);
    $t->call('resizeEvent', 1, bhTs('2026-10-14 08:30'), true)->assertReturned(['ok' => false, 'message' => 'Fuera del horario laboral']);
    expect(Cal::$resized)->toBe([]);

    $t->call('resizeEvent', 1, bhTs('2026-10-14 17:00'), false)->assertReturned(fn (array $r) => $r['ok'] === true);

    expect(Cal::$resized)->toHaveCount(1);
});

it('un evento de día completo tiene que caer en días en que se trabaja, no «caber» en 09:00-17:00', function () {
    $t = Livewire::test(Cal::class);

    // «Congreso» dura 2 días: sábado y domingo no se trabaja.
    $t->call('moveEvent', 2, bhTs('2026-10-17'), true)->assertReturned(['ok' => false, 'message' => 'Fuera del horario laboral']);
    // Viernes y sábado: el sábado no.
    $t->call('moveEvent', 2, bhTs('2026-10-16'), true)->assertReturned(['ok' => false, 'message' => 'Fuera del horario laboral']);
    expect(Cal::$moved)->toBe([]);

    $t->call('moveEvent', 2, bhTs('2026-10-12'), true)->assertReturned(fn (array $r) => $r['ok'] === true);

    expect(Cal::$moved)->toHaveCount(1);
});

it('el día del cambio de horario de otoño el horario sigue siendo el de pared', function () {
    // Domingo 25-oct: el calendario abre ese día (daysOfWeek todos) de 09:00 a 17:00 de pared.
    Cal::$config = [
        'business_hours' => ['daysOfWeek' => [0, 1, 2, 3, 4, 5, 6], 'startTime' => '09:00', 'endTime' => '17:00'],
        'interaction.event_constraint' => 'businessHours',
        'date' => '2026-10-25',
    ];
    Cal::$events = [['id' => 1, 'title' => 'Revisión', 'start' => '2026-10-25T09:00:00+01:00', 'end' => '2026-10-25T10:00:00+01:00']];

    $t = Livewire::test(Cal::class)->set('date', '2026-10-25');

    $t->call('moveEvent', 1, bhTs('2026-10-25 16:00'), false)->assertReturned(fn (array $r) => $r['ok'] === true);
    $t->call('moveEvent', 1, bhTs('2026-10-25 08:30'), false)->assertReturned(['ok' => false, 'message' => 'Fuera del horario laboral']);
});

// ── Recursos ───────────────────────────────────────────────────────────

function bhRooms(): void
{
    // Sala A abre de 14:00 a 20:00, Sala B no tiene horario propio (el del calendario, 09:00-17:00) y la Máquina está siempre abierta.
    Cal::$resources = [
        ['id' => 'a', 'title' => 'Sala A', 'businessHours' => ['startTime' => '14:00', 'endTime' => '20:00']],
        ['id' => 'b', 'title' => 'Sala B'],
        ['id' => 'm', 'title' => 'Máquina', 'businessHours' => false],
    ];
    Cal::$events = [
        ['id' => 1, 'title' => 'En B', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'resourceIds' => ['b']],
        ['id' => 2, 'title' => 'En A y B', 'start' => '2026-10-14 15:00', 'end' => '2026-10-14 16:00', 'resourceIds' => ['a', 'b']],
    ];
}

it('el horario que manda es el del recurso DESTINO, y gana al del calendario', function () {
    bhRooms();
    $t = Livewire::test(Cal::class);

    // 18:00 está fuera del horario del calendario (09-17) pero dentro del de la Sala A (14-20).
    $t->call('moveEvent', 1, bhTs('2026-10-14 18:00'), false, 'a')->assertReturned(fn (array $r) => $r['ok'] === true);
    expect(Cal::$moved[0][1]->resourceIds)->toBe(['a']);
});

it('el rechazo nombra al recurso cuyo horario no se cumple', function () {
    bhRooms();
    $t = Livewire::test(Cal::class);

    // 10:00 está en el horario del calendario, pero la Sala A no abre hasta las 14:00.
    $t->call('moveEvent', 1, bhTs('2026-10-14 10:00'), false, 'a')->assertReturned(['ok' => false, 'message' => 'Fuera del horario de Sala A']);
    // Y la Sala B, que hereda el del calendario, no abre a las 18:00.
    $t->call('moveEvent', 1, bhTs('2026-10-14 18:00'), false, 'b')->assertReturned(['ok' => false, 'message' => 'Fuera del horario de Sala B']);

    expect(Cal::$moved)->toBe([]);
});

it('un recurso sin horario (businessHours false) acepta cualquier hora aunque el calendario tenga uno', function () {
    bhRooms();

    Livewire::test(Cal::class)->call('moveEvent', 1, bhTs('2026-10-17 03:00'), false, 'm')->assertReturned(fn (array $r) => $r['ok'] === true);
});

it('un evento con varios recursos tiene que cumplir el horario de todos', function () {
    bhRooms();
    $t = Livewire::test(Cal::class);

    // El 2 está en A (14-20) y B (09-17): a las 16:30 acaba a las 17:30, fuera del de B.
    $t->call('moveEvent', 2, bhTs('2026-10-14 16:30'), false)->assertReturned(['ok' => false, 'message' => 'Fuera del horario de Sala B']);
    // A las 15:30 acaba a las 16:30: dentro de los dos.
    $t->call('moveEvent', 2, bhTs('2026-10-14 15:30'), false)->assertReturned(fn (array $r) => $r['ok'] === true);
});

// ── Seleccionar ────────────────────────────────────────────────────────

it('seleccionar fuera del horario laboral se rechaza con un toast y no llega a onDateSelect', function () {
    Cal::$config = ['business_hours' => true, 'interaction.select_constraint' => 'businessHours'];

    $t = Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 18:00'), bhTs('2026-10-14 19:00'), false);

    expect(Cal::$selected)->toBe([])
        ->and(bhToast($t))->toMatchArray(['type' => 'error', 'title' => 'Fuera del horario laboral']);

    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 10:00'), bhTs('2026-10-14 11:00'), false);

    expect(Cal::$selected)->toHaveCount(1);
});

it('seleccionar usa el horario del recurso de la columna donde se suelta', function () {
    bhRooms();
    Cal::$config = ['business_hours' => true, 'interaction.select_constraint' => 'businessHours'];

    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 18:00'), bhTs('2026-10-14 19:00'), false, 'a');
    expect(Cal::$selected)->toHaveCount(1);

    $t = Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 10:00'), bhTs('2026-10-14 11:00'), false, 'a');
    expect(Cal::$selected)->toHaveCount(1)
        ->and(bhToast($t)['title'])->toBe('Fuera del horario de Sala A');
});

it('seleccionar días completos pide días laborables', function () {
    Cal::$config = ['business_hours' => true, 'interaction.select_constraint' => 'businessHours'];

    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-16'), bhTs('2026-10-18'), true);
    expect(Cal::$selected)->toBe([]);

    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14'), bhTs('2026-10-16'), true);
    expect(Cal::$selected)->toHaveCount(1);
});

it('la restricción de seleccionar y la de mover son independientes', function () {
    Cal::$config = ['business_hours' => true, 'interaction.select_constraint' => 'businessHours'];

    Livewire::test(Cal::class)->call('moveEvent', 1, bhTs('2026-10-14 20:00'), false)->assertReturned(fn (array $r) => $r['ok'] === true);
});

it('selectOverlap(false): no se puede seleccionar un rango que pisa un evento', function () {
    Cal::$config = ['interaction.select_overlap' => false];

    $t = Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 11:00'), bhTs('2026-10-14 12:00'), false);

    expect(Cal::$selected)->toBe([])
        ->and(bhToast($t))->toMatchArray(['type' => 'error', 'title' => 'Se solapa con «Revisión»']);

    // Tocar el borde no es pisar (la «Revisión» acaba a las 11:30) y un hueco libre vale.
    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 11:30'), bhTs('2026-10-14 12:30'), false);
    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 13:00'), bhTs('2026-10-14 14:00'), false);

    expect(Cal::$selected)->toHaveCount(2);
});

it('por defecto se puede seleccionar sobre un evento', function () {
    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 10:30'), bhTs('2026-10-14 11:00'), false);

    expect(Cal::$selected)->toHaveCount(1);
});

it('selectOverlap(false) con recursos: solo cuentan los eventos de esa columna', function () {
    bhRooms();
    Cal::$config = ['interaction.select_overlap' => false];

    // El 1 está en la sala B, de 10:00 a 11:00.
    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 10:00'), bhTs('2026-10-14 11:00'), false, 'a');
    expect(Cal::$selected)->toHaveCount(1);

    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 10:00'), bhTs('2026-10-14 11:00'), false, 'b');
    expect(Cal::$selected)->toHaveCount(1);
});

it('selectOverlap(false): un día completo choca con un evento de ese día, y un fondo solo si dice overlap false', function () {
    Cal::$config = ['interaction.select_overlap' => false];
    Cal::$events = [
        ['id' => 1, 'title' => 'Festivo', 'start' => '2026-10-12', 'end' => '2026-10-13', 'display' => 'background'],
        ['id' => 2, 'title' => 'Cierre', 'start' => '2026-10-13', 'end' => '2026-10-14', 'display' => 'background', 'overlap' => false],
        ['id' => 3, 'title' => 'Reunión', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00'],
    ];

    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-12'), bhTs('2026-10-12'), true);
    expect(Cal::$selected)->toHaveCount(1);

    $cierre = Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-13'), bhTs('2026-10-13'), true);
    $dia = Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14'), bhTs('2026-10-14'), true);

    // «Cierre» es de fondo: no se dibuja, así que el rechazo no dice su título (solo el de un evento visible).
    expect(Cal::$selected)->toHaveCount(1)
        ->and(bhToast($cierre)['title'])->toBe('Se solapa con otro evento')
        ->and(bhToast($dia)['title'])->toBe('Se solapa con «Reunión»');
});

it('selectOverlap(false) no pide los eventos al seleccionar si no hace falta', function () {
    Cal::$eventCalls = 0;

    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 10:30'), bhTs('2026-10-14 11:00'), false);
    // Una vez al montar (render); seleccionar no vuelve a pedirlos.
    $montar = Cal::$eventCalls;

    Cal::$eventCalls = 0;
    Cal::$config = ['interaction.select_overlap' => false];
    Livewire::test(Cal::class)->call('selectRange', bhTs('2026-10-14 13:00'), bhTs('2026-10-14 14:00'), false);

    expect(Cal::$eventCalls)->toBe($montar + 1);
});

// ── La API fluida de configure() ───────────────────────────────────────

it('configure() cablea businessHours, las restricciones, selectOverlap y highlightConflicts', function () {
    Cal::$config = [];

    $t = Livewire::test(HoursCalendar::class);
    $t->call('moveEvent', 1, bhTs('2026-10-14 20:00'), false)->assertReturned(['ok' => false, 'message' => 'Fuera del horario laboral']);
    $t->call('selectRange', bhTs('2026-10-14 20:00'), bhTs('2026-10-14 21:00'), false);
    $t->call('selectRange', bhTs('2026-10-14 10:30'), bhTs('2026-10-14 11:00'), false);

    expect(Cal::$moved)->toBe([])
        ->and(Cal::$selected)->toBe([])
        ->and($t->html())->toContain('kore-calendar-non-business-v');
});

it('eventConstraint(null) y selectConstraint(null) quitan la regla', function () {
    Cal::$config = ['interaction.event_constraint' => false, 'interaction.select_constraint' => false, 'interaction.select_overlap' => true];

    $t = Livewire::test(HoursCalendar::class);
    $t->call('moveEvent', 1, bhTs('2026-10-14 20:00'), false)->assertReturned(fn (array $r) => $r['ok'] === true);
    $t->call('selectRange', bhTs('2026-10-14 21:00'), bhTs('2026-10-14 22:00'), false);

    expect(Cal::$selected)->toHaveCount(1);
});

it('una restricción desconocida lanza una excepción en español, no se ignora', function () {
    expect(fn () => CalendarSettings::make(['interaction.event_constraint' => 'miGrupo']))
        ->toThrow(InvalidArgumentException::class, "interaction.event_constraint solo admite 'businessHours' o null (llegó «miGrupo»)")
        ->and(fn () => CalendarSettings::make(['interaction.select_constraint' => true]))
        ->toThrow(InvalidArgumentException::class, 'interaction.select_constraint solo admite')
        ->and(CalendarSettings::make(['interaction.event_constraint' => false])->eventConstraint)->toBeNull()
        ->and(CalendarSettings::make(['interaction.event_constraint' => 'businessHours'])->eventConstraint)->toBe('businessHours');
});

it('el cliente recibe qué restricciones marcar en la previsualización', function () {
    $html = Livewire::test(HoursCalendar::class)->html();

    // Js::from escapa las comillas como \u0022.
    expect($html)->toContain('\u0022constrainEvent\u0022:true')->toContain('\u0022constrainSelect\u0022:true');

    Cal::$config = ['interaction.event_constraint' => false];

    expect(Livewire::test(HoursCalendar::class)->html())->toContain('\u0022constrainEvent\u0022:false');
});

// ── Deshacer ───────────────────────────────────────────────────────────

it('deshacer no se rechaza por devolver el evento a un sitio que ya estaba fuera de horario', function () {
    FormCalendar::fresh();
    FormCalendar::$undoable = true;
    FormCalendar::$config = ['business_hours' => true, 'interaction.event_constraint' => 'businessHours'];
    FormCalendar::$events = [['id' => 1, 'title' => 'Importado', 'start' => '2026-10-14 20:00', 'end' => '2026-10-14 21:00']];

    $t = Livewire::test(FormCalendar::class)->call('moveEvent', 1, bhTs('2026-10-14 10:00'), false);
    $t->assertReturned(fn (array $r) => $r['ok'] === true);

    expect(FormCalendar::$moved)->toHaveCount(1);

    // El inverso lo deja a las 20:00, fuera de horario, y aun así se acepta: el usuario está deshaciendo.
    $t->call('undoLastChange');

    expect(FormCalendar::$moved)->toHaveCount(2)
        ->and(FormCalendar::$moved[1][1]->startMarker->toDateTimeString())->toBe('2026-10-14 20:00:00');
});

// ── Conflictos ─────────────────────────────────────────────────────────

function bhConflicts(): void
{
    Cal::$config = [];
    Cal::$events = [
        ['id' => 'A', 'title' => 'Reunión A', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00'],
        ['id' => 'B', 'title' => 'Reunión B', 'start' => '2026-10-14 10:30', 'end' => '2026-10-14 12:00'],
        ['id' => 'C', 'title' => 'Reunión C', 'start' => '2026-10-15 10:00', 'end' => '2026-10-15 11:00'],
    ];
}

it('conflicts() lista los pares del rango visible con sus instancias', function () {
    bhConflicts();

    $pairs = Livewire::test(Cal::class)->instance()->koreTestConflicts();

    expect($pairs)->toHaveCount(1)
        ->and($pairs[0][0])->toBeInstanceOf(EventInstance::class)
        ->and([$pairs[0][0]->event->id, $pairs[0][1]->event->id])->toBe(['A', 'B']);
});

it('conflicts() solo ve el rango dibujado', function () {
    bhConflicts();
    Cal::$events[] = ['id' => 'D', 'title' => 'Otra semana', 'start' => '2026-11-03 10:00', 'end' => '2026-11-03 11:00'];
    Cal::$events[] = ['id' => 'E', 'title' => 'Otra semana 2', 'start' => '2026-11-03 10:30', 'end' => '2026-11-03 11:30'];

    $t = Livewire::test(Cal::class);

    expect($t->instance()->koreTestConflicts())->toHaveCount(1);

    $t->set('date', '2026-11-03');

    expect(array_map(fn ($p) => $p[0]->event->id.$p[1]->event->id, $t->instance()->koreTestConflicts()))->toBe(['DE']);
});

it('highlightConflicts() marca los eventos en conflicto con un icono y un texto para lectores de pantalla', function () {
    bhConflicts();

    $html = Livewire::test(HoursCalendar::class)->html();

    expect(substr_count($html, 'data-conflict'))->toBe(2)
        ->and($html)->toContain('En conflicto con «Reunión B»')
        ->and($html)->toContain('En conflicto con «Reunión A»')
        ->and($html)->not->toContain('En conflicto con «Reunión C»');

    // El icono es decorativo (aria-hidden) y el texto va en sr-only.
    expect($html)->toMatch('/<svg[^>]*aria-hidden="true"[^>]*>/')
        ->and($html)->toMatch('/<span class="sr-only">En conflicto con «Reunión B»<\/span>/');
});

it('sin highlightConflicts() no se marca nada aunque haya conflictos', function () {
    bhConflicts();

    $html = Livewire::test(Cal::class)->html();

    expect($html)->not->toContain('data-conflict')->not->toContain('En conflicto');
});

it('la agenda también marca los conflictos', function () {
    bhConflicts();
    Cal::$config = ['highlight_conflicts' => true];

    $html = Livewire::test(Cal::class)->call('changeView', 'list')->html();

    expect($html)->toContain('En conflicto con «Reunión B»');
});

it('el texto del aviso sale de la traducción', function () {
    bhConflicts();
    config(['kore-calendar.translations.conflict' => 'Clashes with :with']);

    expect(Livewire::test(HoursCalendar::class)->html())->toContain('Clashes with «Reunión B»');
});

it('conflicts() no se puede llamar desde el navegador', function () {
    // Devolvería las instancias serializadas, `meta` incluido.
    expect(fn () => Livewire::test(Cal::class)->call('conflicts'))->toThrow(Exception::class);
});
