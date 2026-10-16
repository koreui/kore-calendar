<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Overlays\EventForm;
use KoreUi\Calendar\Tests\Feature\Fixtures\FormCalendar as Cal;
use KoreUi\Calendar\Tests\Feature\Fixtures\InteractiveCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\NoHooksCalendar;
use Livewire\Livewire;

/*
| Lo que el formulario devuelve al calendario: el evento de Livewire EventForm::SAVED
| con el id del calendario y los datos. El calendario no se fía de nada: vuelve a
| buscar el evento entre los de la vista, vuelve a comprobar los datos y solo
| entonces llama a createEvent / updateEvent / deleteEvent, dentro de una transacción.
|
| Los eventos de siempre (InteractiveCalendar::defaultEvents), semana del 12 al 18 de
| octubre de 2026 en Europe/Madrid:
|   1 «Revisión»  mié 14, 10:00-11:30
|   2 «Congreso»  jue 15 → vie 16 (día completo, [15, 17))
|   3 «Bloqueo»   vie 16, 15:00-16:00
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
    Cal::fresh();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** El formulario devuelve su resultado: lo que llega al calendario. */
function ffSave($testable, array $data, ?string $calendar = null)
{
    return $testable->dispatch(EventForm::SAVED, calendar: $calendar ?? $testable->id(), data: $data);
}

/** Los datos de un formulario válido, para crear. */
function ffCreate(array $over = []): array
{
    return [
        'mode' => 'create', 'id' => null, 'title' => 'Nueva reunión', 'allDay' => false,
        'start' => '2026-10-15 14:00:00', 'end' => '2026-10-15 15:00:00', 'resourceId' => null, 'color' => null,
        ...$over,
    ];
}

function ffEdit(array $over = []): array
{
    return ffCreate(['mode' => 'edit', 'id' => 1, 'title' => 'Revisión final', ...$over]);
}

/** El toast que se envió, o null. */
function ffToast($testable): ?array
{
    $toast = collect(data_get($testable->effects, 'dispatches'))->firstWhere('name', 'kore:toast');

    return $toast['params']['toast'] ?? null;
}

// ── Crear ──────────────────────────────────────────────────────────────

it('crear llama a createEvent con los datos limpios y avisa con un toast de éxito', function () {
    $t = ffSave(Livewire::test(Cal::class), ffCreate(['color' => 'info']));

    expect(Cal::$created)->toHaveCount(1);
    [$data, $nivel] = Cal::$created[0];

    expect($data)->toMatchArray([
        'mode' => 'create', 'id' => null, 'title' => 'Nueva reunión', 'allDay' => false,
        'start' => '2026-10-15 14:00:00', 'end' => '2026-10-15 15:00:00',
        'resourceId' => null, 'resourceIds' => [], 'color' => 'info',
    ])
        // Los mismos horarios como instantes, en la zona del calendario, listos para guardar.
        ->and($data['startAt']->format('c'))->toBe('2026-10-15T14:00:00+02:00')
        ->and($data['endAt']->format('c'))->toBe('2026-10-15T15:00:00+02:00')
        // Dentro de una transacción.
        ->and($nivel)->toBe(1);

    expect(ffToast($t))->toMatchArray(['type' => 'success', 'title' => '«Nueva reunión» creado']);
});

it('un evento de día completo llega con las fechas, a las 00:00 de la zona', function () {
    ffSave(Livewire::test(Cal::class), ffCreate(['allDay' => true, 'start' => '2026-10-20', 'end' => '2026-10-22']));

    $data = Cal::$created[0][0];

    expect($data['allDay'])->toBeTrue()
        ->and($data['start'])->toBe('2026-10-20')
        ->and($data['end'])->toBe('2026-10-22')
        ->and($data['startAt']->format('c'))->toBe('2026-10-20T00:00:00+02:00')
        ->and($data['endAt']->format('c'))->toBe('2026-10-22T00:00:00+02:00');
});

it('sin fin, endAt es null', function () {
    ffSave(Livewire::test(Cal::class), ffCreate(['end' => null]));

    expect(Cal::$created[0][0]['end'])->toBeNull()
        ->and(Cal::$created[0][0]['endAt'])->toBeNull();
});

it('las horas se resuelven con las reglas del horario de verano del calendario', function () {
    // El domingo 25 de octubre de 2026 a las 02:30 Madrid existe dos veces: la primera.
    ffSave(Livewire::test(Cal::class), ffCreate(['start' => '2026-10-25 02:30:00', 'end' => '2026-10-25 03:30:00']));

    expect(Cal::$created[0][0]['startAt']->format('c'))->toBe('2026-10-25T02:30:00+02:00');
});

it('el recurso llega con su id de verdad (no el texto del formulario)', function () {
    Cal::$resources = [['id' => 10, 'title' => 'Sala A'], ['id' => 20, 'title' => 'Sala B']];

    ffSave(Livewire::test(Cal::class), ffCreate(['resourceId' => '20']));

    expect(Cal::$created[0][0]['resourceId'])->toBe(20)
        ->and(Cal::$created[0][0]['resourceIds'])->toBe([20]);
});

it('un recurso que no existe se rechaza', function () {
    Cal::$resources = [['id' => 10, 'title' => 'Sala A']];

    $t = ffSave(Livewire::test(Cal::class), ffCreate(['resourceId' => '999']));

    expect(Cal::$created)->toBe([])
        ->and(ffToast($t))->toMatchArray(['type' => 'error', 'title' => 'No se pudo crear el evento', 'description' => 'El recurso de destino no existe']);
});

it('un calendario sin recursos rechaza cualquier recurso', function () {
    ffSave(Livewire::test(Cal::class), ffCreate(['resourceId' => '1']));

    expect(Cal::$created)->toBe([]);
});

it('valid_range también vale para crear y para editar', function () {
    Cal::$config = ['interaction.valid_range' => ['start' => '2026-10-12', 'end' => '2026-10-18']];

    // Dentro.
    ffSave(Livewire::test(Cal::class), ffCreate());
    expect(Cal::$created)->toHaveCount(1);

    // Fuera: crear, y editar un evento que existe.
    $crear = ffSave(Livewire::test(Cal::class), ffCreate(['start' => '2026-10-19 10:00:00', 'end' => '2026-10-19 11:00:00']));
    $editar = ffSave(Livewire::test(Cal::class), ffEdit(['start' => '2026-10-10 10:00:00', 'end' => '2026-10-10 11:00:00']));

    expect(Cal::$created)->toHaveCount(1)
        ->and(Cal::$updated)->toBe([])
        ->and(ffToast($crear)['description'])->toBe('Fuera del rango permitido')
        ->and(ffToast($editar)['title'])->toBe('No se pudo guardar el evento');
});

// ── Editar ─────────────────────────────────────────────────────────────

it('editar llama a updateEvent con el evento actual y los datos', function () {
    $t = ffSave(Livewire::test(Cal::class), ffEdit(['color' => 'success']));

    expect(Cal::$updated)->toHaveCount(1);
    [$event, $data, $nivel] = Cal::$updated[0];

    expect($event)->toBeInstanceOf(CalendarEvent::class)
        // El evento es el de events(), no uno reconstruido con lo que mandó el navegador.
        ->and($event->id)->toBe(1)
        ->and($event->title)->toBe('Revisión')
        ->and($data)->toMatchArray(['mode' => 'edit', 'id' => 1, 'title' => 'Revisión final', 'color' => 'success'])
        ->and($data['startAt']->format('c'))->toBe('2026-10-15T14:00:00+02:00')
        ->and($nivel)->toBe(1);

    expect(ffToast($t))->toMatchArray(['type' => 'success', 'title' => '«Revisión final» guardado']);
});

it('el id llega por JSON como texto o como número: es el mismo evento', function () {
    ffSave(Livewire::test(Cal::class), ffEdit(['id' => '1']));

    expect(Cal::$updated)->toHaveCount(1)->and(Cal::$updated[0][0]->id)->toBe(1);
});

it('un evento con varios recursos los conserva si no se toca el primero', function () {
    Cal::$resources = [['id' => 10, 'title' => 'A'], ['id' => 20, 'title' => 'B']];
    Cal::$events = [['id' => 1, 'title' => 'Doble', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'resourceIds' => [10, 20]]];

    ffSave(Livewire::test(Cal::class), ffEdit(['resourceId' => '10']));
    ffSave(Livewire::test(Cal::class), ffEdit(['resourceId' => '20']));
    ffSave(Livewire::test(Cal::class), ffEdit(['resourceId' => null]));

    expect(Cal::$updated[0][1]['resourceIds'])->toBe([10, 20])
        ->and(Cal::$updated[1][1]['resourceIds'])->toBe([20])
        ->and(Cal::$updated[2][1]['resourceIds'])->toBe([]);
});

it('el color de un evento que no es de la lista se conserva al guardar, pero no se acepta uno nuevo', function () {
    Cal::$events = [['id' => 1, 'title' => 'Naranja', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'color' => '#ff8800']];

    ffSave(Livewire::test(Cal::class), ffEdit(['color' => '#ff8800']));
    expect(Cal::$updated)->toHaveCount(1)->and(Cal::$updated[0][1]['color'])->toBe('#ff8800');

    // Otro color fuera de la lista: no. En el evento, ni en uno nuevo.
    ffSave(Livewire::test(Cal::class), ffEdit(['color' => '#00ff00']));
    ffSave(Livewire::test(Cal::class), ffCreate(['color' => '#ff8800']));
    expect(Cal::$updated)->toHaveCount(1)->and(Cal::$created)->toBe([]);
});

it('IDOR: un evento que events() no devuelve para esta vista no se puede editar', function () {
    Cal::$events = [InteractiveCalendar::defaultEvents()[0]];

    $t = ffSave(Livewire::test(Cal::class), ffEdit(['id' => 77]));

    expect(Cal::$updated)->toBe([])
        ->and(ffToast($t))->toMatchArray(['type' => 'error', 'title' => 'No se pudo guardar el evento', 'description' => 'El evento ya no está disponible'])
        // La vista está desfasada: se repinta para que desaparezca.
        ->and(data_get($t->effects, 'html'))->not->toBeNull();
});

it('IDOR: uno de otra semana tampoco, pero sí desde la vista donde está', function () {
    Cal::$events = [
        ...InteractiveCalendar::defaultEvents(),
        ['id' => 55, 'title' => 'Otra semana', 'start' => '2026-11-20 10:00', 'end' => '2026-11-20 11:00'],
    ];

    ffSave(Livewire::test(Cal::class), ffEdit(['id' => 55, 'start' => '2026-11-20 12:00:00', 'end' => '2026-11-20 13:00:00']));
    expect(Cal::$updated)->toBe([]);

    ffSave(Livewire::test(Cal::class)->set('date', '2026-11-20'), ffEdit(['id' => 55, 'start' => '2026-11-20 12:00:00', 'end' => '2026-11-20 13:00:00']));
    expect(Cal::$updated)->toHaveCount(1);
});

it('un evento editable:false no se cambia desde el formulario ni se elimina', function () {
    Cal::$events = [['id' => 1, 'title' => 'Festivo', 'start' => '2026-10-14', 'editable' => false]];

    $editar = ffSave(Livewire::test(Cal::class), ffEdit());
    $eliminar = ffSave(Livewire::test(Cal::class), ['mode' => 'delete', 'id' => 1]);

    expect(Cal::$updated)->toBe([])
        ->and(Cal::$deleted)->toBe([])
        ->and(ffToast($editar)['description'])->toBe('Este evento no se puede editar')
        ->and(ffToast($eliminar))->toMatchArray(['title' => 'No se pudo eliminar el evento', 'description' => 'Este evento no se puede editar']);
});

// ── Eliminar ───────────────────────────────────────────────────────────

it('eliminar llama a deleteEvent con el evento actual', function () {
    $t = ffSave(Livewire::test(Cal::class), ['mode' => 'delete', 'id' => 1]);

    expect(Cal::$deleted)->toHaveCount(1);
    [$event, $nivel] = Cal::$deleted[0];

    expect($event)->toBeInstanceOf(CalendarEvent::class)
        ->and($event->id)->toBe(1)
        ->and($event->title)->toBe('Revisión')
        ->and($nivel)->toBe(1)
        ->and(ffToast($t))->toMatchArray(['type' => 'success', 'title' => '«Revisión» eliminado']);
});

it('IDOR: no se elimina un evento ajeno', function () {
    Cal::$events = [InteractiveCalendar::defaultEvents()[0]];

    $t = ffSave(Livewire::test(Cal::class), ['mode' => 'delete', 'id' => 77]);

    expect(Cal::$deleted)->toBe([])
        ->and(ffToast($t))->toMatchArray(['title' => 'No se pudo eliminar el evento', 'description' => 'El evento ya no está disponible']);
});

it('eliminar sin id, o con un id que no es un id, no llega al hook', function (mixed $id) {
    ffSave(Livewire::test(Cal::class), ['mode' => 'delete', 'id' => $id]);

    expect(Cal::$deleted)->toBe([]);
})->with(['sin id' => [null], 'lista' => [[1]], 'booleano' => [true]]);

// ── Rechazos ───────────────────────────────────────────────────────────

it('un hook que devuelve false rechaza con el mensaje genérico', function () {
    Cal::$hookResult = false;

    $t = ffSave(Livewire::test(Cal::class), ffCreate());

    expect(ffToast($t))->toMatchArray(['type' => 'error', 'title' => 'No se pudo crear el evento', 'description' => 'No se pudo guardar el cambio']);
});

it('un hook que devuelve un texto rechaza con ese motivo, en crear, editar y eliminar', function () {
    Cal::$hookResult = 'La sala está ocupada';

    $crear = ffSave(Livewire::test(Cal::class), ffCreate());
    $editar = ffSave(Livewire::test(Cal::class), ffEdit());
    $eliminar = ffSave(Livewire::test(Cal::class), ['mode' => 'delete', 'id' => 1]);

    expect(ffToast($crear))->toMatchArray(['title' => 'No se pudo crear el evento', 'description' => 'La sala está ocupada'])
        ->and(ffToast($editar))->toMatchArray(['title' => 'No se pudo guardar el evento', 'description' => 'La sala está ocupada'])
        ->and(ffToast($eliminar))->toMatchArray(['title' => 'No se pudo eliminar el evento', 'description' => 'La sala está ocupada']);
});

it('null y true aceptan', function (mixed $resultado) {
    Cal::$hookResult = $resultado;

    expect(ffToast(ffSave(Livewire::test(Cal::class), ffCreate()))['type'])->toBe('success');
})->with(['null' => [null], 'true' => [true]]);

it('un rechazo no repinta el calendario: nada cambió', function () {
    Cal::$hookResult = 'No';

    $t = ffSave(Livewire::test(Cal::class), ffCreate());

    expect(data_get($t->effects, 'html'))->toBeNull();
});

it('una excepción del hook se reporta y se rechaza con el mensaje genérico', function () {
    Exceptions::fake();
    Cal::$hookResult = fn () => throw new RuntimeException('se cayó la base de datos');

    $t = ffSave(Livewire::test(Cal::class), ffCreate());

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'se cayó la base de datos');
    expect(ffToast($t))->toMatchArray(['title' => 'No se pudo crear el evento', 'description' => 'No se pudo guardar el cambio']);
});

it('una ValidationException muestra su motivo y una AuthorizationException, que no se puede editar', function () {
    Cal::$hookResult = fn () => throw ValidationException::withMessages(['title' => 'Ya existe un evento con ese título']);
    expect(ffToast(ffSave(Livewire::test(Cal::class), ffCreate()))['description'])->toBe('Ya existe un evento con ese título');

    Cal::$hookResult = fn () => throw new AuthorizationException;
    expect(ffToast(ffSave(Livewire::test(Cal::class), ffEdit()))['description'])->toBe('Este evento no se puede editar');
});

it('por defecto los hooks rechazan y explican qué hay que implementar y dónde', function () {
    $t = Livewire::test(NoHooksCalendar::class);

    $mensajes = [
        'createEvent' => ffToast(ffSave($t, ffCreate()))['description'],
        'updateEvent' => ffToast(ffSave($t, ffEdit()))['description'],
        'deleteEvent' => ffToast(ffSave($t, ['mode' => 'delete', 'id' => 1]))['description'],
    ];

    foreach ($mensajes as $hook => $mensaje) {
        expect($mensaje)->toContain("implementa {$hook}()")->toContain(NoHooksCalendar::class);
    }
});

// ── Lo que el calendario no acepta ─────────────────────────────────────

it('un aviso para otro calendario se ignora: el evento es global', function () {
    $t = ffSave(Livewire::test(Cal::class), ffCreate(), 'otro-calendario');

    expect(Cal::$created)->toBe([])
        ->and(ffToast($t))->toBeNull()
        ->and(data_get($t->effects, 'html'))->toBeNull();
});

it('un calendario sin formulario ignora el aviso aunque lleve su id: lo puede lanzar cualquiera', function () {
    Cal::$form = false;

    $t = ffSave(Livewire::test(Cal::class), ffCreate());

    expect(Cal::$created)->toBe([])->and(ffToast($t))->toBeNull();
});

it('un modo desconocido se rechaza', function (mixed $modo) {
    $t = ffSave(Livewire::test(Cal::class), ffCreate(['mode' => $modo]));

    expect(Cal::$created)->toBe([])
        ->and(Cal::$updated)->toBe([])
        ->and(ffToast($t)['description'])->toBe('El cambio no es válido');
})->with(['otro' => ['archive'], 'vacío' => [''], 'lista' => [['create']]]);

it('lo que llega mal formado no llega al hook', function (array $cambio) {
    $t = ffSave(Livewire::test(Cal::class), ffCreate($cambio));

    expect(Cal::$created)->toBe([])
        ->and(ffToast($t)['description'])->toBe('El cambio no es válido');
})->with([
    'sin título' => [['title' => '']],
    'título en blanco' => [['title' => "  \n "]],
    'título que no es texto' => [['title' => ['x']]],
    'título larguísimo' => [['title' => str_repeat('a', 256)]],
    'allDay como texto' => [['allDay' => 'true']],
    'inicio inventado' => [['start' => 'mañana']],
    'inicio con día que no existe' => [['start' => '2026-02-30 10:00:00']],
    'inicio con hora que no existe' => [['start' => '2026-10-15 25:00:00']],
    'inicio sin hora en un evento con hora' => [['start' => '2026-10-15']],
    'inicio con hora en un día completo' => [['allDay' => true, 'start' => '2026-10-15 10:00:00', 'end' => null]],
    'fin inventado' => [['end' => 'pasado mañana']],
    'fin antes del inicio' => [['end' => '2026-10-15 13:59:59']],
    'día completo con fin igual al inicio (exclusivo)' => [['allDay' => true, 'start' => '2026-10-15', 'end' => '2026-10-15']],
    'año fuera de rango' => [['start' => '0000-01-01 10:00:00', 'end' => null]],
    'color que no existe' => [['color' => 'rojo']],
    'color que no es texto' => [['color' => ['primary']]],
    'recurso que no es un id' => [['resourceId' => ['1']]],
]);

it('el título se limpia de espacios', function () {
    ffSave(Livewire::test(Cal::class), ffCreate(['title' => "  Con espacios \n"]));

    expect(Cal::$created[0][0]['title'])->toBe('Con espacios');
});

it('un título con HTML llega tal cual al hook: escapar es cosa de quien lo pinta', function () {
    ffSave(Livewire::test(Cal::class), ffCreate(['title' => '<b>x</b>']));

    expect(Cal::$created[0][0]['title'])->toBe('<b>x</b>');
});

it('el formulario tras guardar repinta el calendario con lo que events() devuelva ahora', function () {
    $t = Livewire::test(Cal::class);

    Cal::$events = [...InteractiveCalendar::defaultEvents(), ['id' => 9, 'title' => 'Recién creado', 'start' => '2026-10-15 14:00', 'end' => '2026-10-15 15:00']];

    expect(ffSave($t, ffCreate())->html())->toContain('Recién creado');
});
