<?php

use KoreUi\Calendar\Interaction\EventFormPayload;
use KoreUi\Calendar\Overlays\EventForm;
use KoreUi\Overlay\OverlayManager;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

/*
| El formulario de evento como overlay. No guarda nada: valida y devuelve los datos
| al calendario con un evento de Livewire (EventForm::SAVED) y cierra el overlay.
| Las horas son de pared, el fin de un evento de día completo es INCLUSIVO en el
| formulario y EXCLUSIVO en lo que devuelve.
*/

beforeEach(function () {
    app()->setLocale('es');
});

const EF_CAL = 'calendar-id-1';

/** Los argumentos con los que el calendario abre el formulario. */
function efArgs(array $over = []): array
{
    return [
        'calendar' => EF_CAL,
        'mode' => 'create',
        'start' => '2026-10-15 10:00:00',
        'end' => '2026-10-15 11:00:00',
        ...$over,
    ];
}

/** Los params del primer evento de Livewire con ese nombre, o null. */
function efDispatched($testable, string $name): ?array
{
    return collect(data_get($testable->effects, 'dispatches'))->firstWhere('name', $name)['params'] ?? null;
}

// ── Overlay ────────────────────────────────────────────────────────────

it('es un overlay de kore-ui: un modal que no se cierra con un clic fuera', function () {
    expect(new EventForm)->toBeInstanceOf(KoreUi\Core\Contracts\Overlayable::class)
        ->and(EventForm::overlayType())->toBe('modal')
        ->and(EventForm::overlaySize())->toBe('lg')
        ->and(EventForm::closesOnClickAway())->toBeFalse()
        ->and(EventForm::closesOnEscape())->toBeTrue()
        ->and(EventForm::destroysOnClose())->toBeTrue();
});

it('el OverlayManager de kore-ui lo abre por su nombre', function () {
    $manager = Livewire::test(OverlayManager::class)
        ->dispatch('kore:open', name: 'kore-calendar-event-form', arguments: efArgs(), overlayAttributes: ['title' => 'Nuevo evento'])
        ->assertDispatched('kore:overlay-changed');

    $abierto = array_values($manager->get('overlays'))[0];

    expect($abierto['name'])->toBe('kore-calendar-event-form')
        ->and($abierto['overlayAttributes']['type'])->toBe('modal')
        ->and($abierto['overlayAttributes']['title'])->toBe('Nuevo evento')
        ->and($abierto['overlayAttributes']['closesOnClickAway'])->toBeFalse()
        ->and($abierto['arguments']['calendar'])->toBe(EF_CAL);
});

// ── Pintar ─────────────────────────────────────────────────────────────

it('al crear precarga el rango y no ofrece eliminar', function () {
    Livewire::test(EventForm::class, efArgs())
        ->assertSet('startDate', '2026-10-15')
        ->assertSet('startTime', '10:00')
        ->assertSet('endDate', '2026-10-15')
        ->assertSet('endTime', '11:00')
        ->assertSet('allDay', false)
        ->assertSee('Nuevo evento')
        ->assertSee('Guardar')
        ->assertDontSee('Eliminar');
});

it('al editar precarga el evento y ofrece eliminar', function () {
    Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 7, 'title' => 'Revisión', 'color' => 'success']))
        ->assertSet('title', 'Revisión')
        ->assertSet('color', 'success')
        ->assertSee('Editar evento')
        ->assertSee('Eliminar');
});

it('todos los textos salen de la configuración: otro idioma, otras palabras', function () {
    config([
        'kore-calendar.translations.form_create' => 'New event',
        'kore-calendar.translations.form_save' => 'Save',
        'kore-calendar.translations.form_title' => 'Title',
    ]);

    Livewire::test(EventForm::class, efArgs())
        ->assertSee('New event')
        ->assertSee('Save')
        ->assertSee('Title')
        ->assertDontSee('Nuevo evento');
});

it('un evento de día completo se edita con el fin INCLUSIVO', function () {
    // «Congreso», 15 → 17 exclusivo: ocupa el 15 y el 16.
    Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 2, 'allDay' => true, 'start' => '2026-10-15', 'end' => '2026-10-17']))
        ->assertSet('startDate', '2026-10-15')
        ->assertSet('endDate', '2026-10-16')
        ->assertSet('startTime', '')
        ->assertSet('endTime', '');
});

it('un evento sin fin deja los campos de fin vacíos', function () {
    Livewire::test(EventForm::class, efArgs(['end' => null]))
        ->assertSet('endDate', '')
        ->assertSet('endTime', '');
});

it('el selector de recurso solo está si el calendario tiene recursos', function () {
    Livewire::test(EventForm::class, efArgs())->assertDontSee('Sin recurso');

    Livewire::test(EventForm::class, efArgs(['resources' => [['value' => '1', 'label' => 'Sala A'], ['value' => '2', 'label' => 'Sala B']], 'resourceId' => 2]))
        ->assertSet('resourceId', '2')
        ->assertSee('Sala A')
        ->assertSee('Sala B');
});

it('ofrece los trece colores traducidos, y el suyo si no es de la lista', function () {
    // Las opciones viajan en un nodo JSON (el panel del select se teleporta y el morph no lo alcanza):
    // los acentos van escapados como \uXXXX.
    $t = Livewire::test(EventForm::class, efArgs())
        ->assertSee('Primario')->assertSee('Paleta 1')->assertSee('Paleta 8')->assertSee('Peligro')
        ->assertSeeHtml('\u00c9xito');

    expect($t->html())->not->toContain('#ff8800');

    Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 1, 'color' => '#ff8800']))
        ->assertSee('#ff8800')
        ->assertSet('color', '#ff8800');
});

/** Las opciones del selector de color, tal como viajan en el JSON de la página. */
function efOpcionesDeColor($t): array
{
    preg_match_all('#<script type="application/json"[^>]*>(.*?)</script>#s', $t->html(), $m);

    foreach ($m[1] as $json) {
        if (str_contains($json, 'Primario')) {
            return json_decode($json, true);
        }
    }

    return [];
}

it('un color propio válido se ofrece con una etiqueta fija, nunca con su valor', function (string $color) {
    $opciones = efOpcionesDeColor(Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 1, 'color' => $color])));

    // La primera opción es la suya: se puede elegir (y guardar sin borrarla), pero el nombre no es el valor.
    expect($opciones[0])->toBe(['value' => $color, 'label' => 'Personalizado'])
        ->and(array_column($opciones, 'value'))->toContain('primary');
})->with(['hex' => ['#ff8800'], 'rgb' => ['rgb(255 136 0 / 50%)'], 'nombre' => ['rebeccapurple'], 'oklch' => ['oklch(0.7 0.15 50)'], 'token fuera de la lista' => ['muted']]);

it('un color propio que no es un color CSS no se ofrece ni se pinta en la página', function (string $color) {
    $t = Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 1, 'color' => $color]));
    $opciones = efOpcionesDeColor($t);

    // kore-ui pega ese JSON sin escapar en la página: un `<!--` o un `</script>` en él la rompería.
    expect(json_encode($opciones))->not->toContain('script')->and(json_encode($opciones))->not->toContain('onerror')
        ->and(array_column($opciones, 'value'))->not->toContain($color)
        ->and(array_column($opciones, 'label'))->not->toContain($color)
        ->and($opciones)->toHaveCount(count(EventFormPayload::COLORS))
        ->and($t->html())->not->toContain('<!--<script>')->not->toContain('<img src=x');
})->with([
    'comentario' => ['<!--<script>'],
    'cierre de script' => ['</script><img src=x onerror=alert(1)>'],
    'atributo' => ['"><img src=x onerror=alert(2)>'],
    'declaración extra' => ['red; background: url(x)'],
    'url' => ['url(javascript:alert(1))'],
]);

it('los selectores de hora se esconden con el estado del navegador, sin ir al servidor', function () {
    // `$wire.allDay`: el servidor no tiene que pintar de nuevo para que el interruptor funcione.
    Livewire::test(EventForm::class, efArgs())->assertSeeHtml('$wire.allDay');
});

// ── Guardar: crear ─────────────────────────────────────────────────────

it('guardar devuelve los datos al calendario con closeWith y cierra el overlay', function () {
    $t = Livewire::test(EventForm::class, efArgs())
        ->set('title', '  Revisión  ')
        ->set('color', 'info')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('kore:close');

    expect(efDispatched($t, EventForm::SAVED))->toBe([
        'calendar' => EF_CAL,
        'data' => [
            'mode' => 'create',
            'id' => null,
            'title' => 'Revisión',
            'allDay' => false,
            'start' => '2026-10-15 10:00:00',
            'end' => '2026-10-15 11:00:00',
            'resourceId' => null,
            'color' => 'info',
        ],
    ]);
});

it('el evento va con un nombre propio del paquete', function () {
    expect(EventForm::SAVED)->toBe('kore-calendar:event-saved');
});

it('un día completo devuelve el fin EXCLUSIVO (el inclusivo + 1 día)', function () {
    $t = Livewire::test(EventForm::class, efArgs(['allDay' => true, 'start' => '2026-10-14', 'end' => '2026-10-16']))
        // En el formulario acaba el 15 (inclusivo).
        ->assertSet('endDate', '2026-10-15')
        ->set('title', 'Viaje')
        ->set('endDate', '2026-10-20')
        ->call('save')
        ->assertHasNoErrors();

    $data = efDispatched($t, EventForm::SAVED)['data'];

    expect($data['allDay'])->toBeTrue()
        ->and($data['start'])->toBe('2026-10-14')
        ->and($data['end'])->toBe('2026-10-21');
});

it('un día completo de un solo día: el fin inclusivo es el propio día, el exclusivo el siguiente', function () {
    $t = Livewire::test(EventForm::class, efArgs(['allDay' => true, 'start' => '2026-10-14', 'end' => '2026-10-15']))
        ->assertSet('endDate', '2026-10-14')
        ->set('title', 'Festivo')
        ->call('save');

    expect(efDispatched($t, EventForm::SAVED)['data'])->toMatchArray(['start' => '2026-10-14', 'end' => '2026-10-15']);
});

it('en un día completo las horas se ignoran aunque hubiera algo escrito', function () {
    $t = Livewire::test(EventForm::class, efArgs())
        ->set('title', 'Todo el día')
        ->set('allDay', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(efDispatched($t, EventForm::SAVED)['data'])->toMatchArray(['allDay' => true, 'start' => '2026-10-15', 'end' => '2026-10-16']);
});

it('el fin es opcional: sin fecha ni hora de fin devuelve null', function () {
    $t = Livewire::test(EventForm::class, efArgs(['end' => null]))
        ->set('title', 'Sin fin')
        ->call('save')
        ->assertHasNoErrors();

    expect(efDispatched($t, EventForm::SAVED)['data']['end'])->toBeNull();

    $dia = Livewire::test(EventForm::class, efArgs(['allDay' => true, 'start' => '2026-10-14', 'end' => null]))
        ->set('title', 'Sin fin')
        ->call('save');

    expect(efDispatched($dia, EventForm::SAVED)['data']['end'])->toBeNull();
});

it('una hora de fin sin fecha es ese mismo día', function () {
    $t = Livewire::test(EventForm::class, efArgs(['end' => null]))
        ->set('title', 'Reunión')
        ->set('endTime', '12:30')
        ->call('save')
        ->assertHasNoErrors();

    expect(efDispatched($t, EventForm::SAVED)['data']['end'])->toBe('2026-10-15 12:30:00');
});

it('el recurso viaja como texto, y vacío es ninguno', function () {
    $recursos = [['value' => '1', 'label' => 'Sala A'], ['value' => '2', 'label' => 'Sala B']];

    $t = Livewire::test(EventForm::class, efArgs(['resources' => $recursos, 'resourceId' => 2]))
        ->set('title', 'Reunión')
        ->call('save');

    expect(efDispatched($t, EventForm::SAVED)['data']['resourceId'])->toBe('2');

    $sin = Livewire::test(EventForm::class, efArgs(['resources' => $recursos, 'resourceId' => 2]))
        ->set('title', 'Reunión')
        ->set('resourceId', null)
        ->set('color', '')
        ->call('save');

    expect(efDispatched($sin, EventForm::SAVED)['data'])->toMatchArray(['resourceId' => null, 'color' => null]);
});

// ── Guardar: validación ────────────────────────────────────────────────

it('el título es obligatorio y no se devuelve nada', function () {
    $t = Livewire::test(EventForm::class, efArgs())
        ->set('title', '   ')
        ->call('save')
        ->assertHasErrors(['title' => 'required'])
        ->assertSee('Este campo es obligatorio')
        ->assertNotDispatched(EventForm::SAVED)
        ->assertNotDispatched('kore:close');

    expect($t->errors()->first('title'))->toBe('Este campo es obligatorio');
});

it('el título no pasa de 255 caracteres', function () {
    Livewire::test(EventForm::class, efArgs())
        ->set('title', str_repeat('a', 256))
        ->call('save')
        ->assertHasErrors(['title' => 'max'])
        ->assertNotDispatched(EventForm::SAVED);

    Livewire::test(EventForm::class, efArgs())
        ->set('title', str_repeat('a', 255))
        ->call('save')
        ->assertHasNoErrors();
});

it('el inicio es obligatorio: fecha, y hora si no es de día completo', function () {
    Livewire::test(EventForm::class, efArgs(['start' => null, 'end' => null]))
        ->set('title', 'x')
        ->call('save')
        ->assertHasErrors(['startDate' => 'required', 'startTime' => 'required']);

    // Un día completo no necesita hora.
    Livewire::test(EventForm::class, efArgs(['allDay' => true, 'start' => '2026-10-15', 'end' => null]))
        ->set('title', 'x')
        ->call('save')
        ->assertHasNoErrors();
});

it('una fecha o una hora mal escritas no pasan', function () {
    Livewire::test(EventForm::class, efArgs())
        ->set('title', 'x')
        ->set('startDate', '2026-02-30')
        ->set('startTime', '25:00')
        ->call('save')
        ->assertHasErrors(['startDate' => 'date_format', 'startTime' => 'date_format'])
        ->assertSee('El valor no es válido')
        ->assertNotDispatched(EventForm::SAVED);
});

it('el fin no puede ser anterior al inicio', function () {
    // Otro día.
    Livewire::test(EventForm::class, efArgs())
        ->set('title', 'x')
        ->set('endDate', '2026-10-14')
        ->call('save')
        ->assertHasErrors(['endDate'])
        ->assertSee('El fin no puede ser anterior al inicio')
        ->assertNotDispatched(EventForm::SAVED);

    // El mismo día, antes de la hora de inicio.
    Livewire::test(EventForm::class, efArgs())
        ->set('title', 'x')
        ->set('endTime', '09:59')
        ->call('save')
        ->assertHasErrors(['endTime'])
        ->assertNotDispatched(EventForm::SAVED);

    // Un día completo.
    Livewire::test(EventForm::class, efArgs(['allDay' => true, 'start' => '2026-10-15', 'end' => null]))
        ->set('title', 'x')
        ->set('endDate', '2026-10-14')
        ->call('save')
        ->assertHasErrors(['endDate'])
        ->assertNotDispatched(EventForm::SAVED);
});

it('el fin puede coincidir con el inicio', function () {
    // El normalizador del calendario le pondrá la duración por defecto; el formulario no lo prohíbe.
    Livewire::test(EventForm::class, efArgs())
        ->set('title', 'x')
        ->set('endTime', '10:00')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(EventForm::class, efArgs(['allDay' => true, 'start' => '2026-10-15', 'end' => null]))
        ->set('title', 'x')
        ->set('endDate', '2026-10-15')
        ->call('save')
        ->assertHasNoErrors();
});

it('una fecha de fin sin hora de fin no se adivina', function () {
    Livewire::test(EventForm::class, efArgs(['end' => null]))
        ->set('title', 'x')
        ->set('endDate', '2026-10-16')
        ->call('save')
        ->assertHasErrors(['endTime' => 'required_with'])
        ->assertNotDispatched(EventForm::SAVED);
});

it('un recurso o un color que no están en la lista no pasan', function () {
    Livewire::test(EventForm::class, efArgs(['resources' => [['value' => '1', 'label' => 'Sala A']]]))
        ->set('title', 'x')
        ->set('resourceId', '99')
        ->set('color', 'rojo')
        ->call('save')
        ->assertHasErrors(['resourceId' => 'in', 'color' => 'in'])
        ->assertNotDispatched(EventForm::SAVED);
});

it('un selector vaciado con su aspa (null) no rompe', function () {
    Livewire::test(EventForm::class, efArgs())
        ->set('title', 'x')
        ->set('endDate', null)
        ->set('endTime', null)
        ->call('save')
        ->assertHasNoErrors();
});

// ── Guardar: editar ────────────────────────────────────────────────────

it('editar devuelve el id del evento y el modo', function () {
    $t = Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 7, 'title' => 'Revisión']))
        ->set('title', 'Revisión final')
        ->call('save')
        ->assertHasNoErrors();

    expect(efDispatched($t, EventForm::SAVED))->toMatchArray([
        'calendar' => EF_CAL,
        'data' => [
            'mode' => 'edit', 'id' => 7, 'title' => 'Revisión final', 'allDay' => false,
            'start' => '2026-10-15 10:00:00', 'end' => '2026-10-15 11:00:00', 'resourceId' => null, 'color' => null,
        ],
    ]);
});

it('el id de un evento de texto viaja como texto', function () {
    $t = Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 'abc-9', 'title' => 'x']))->call('save');

    expect(efDispatched($t, EventForm::SAVED)['data']['id'])->toBe('abc-9');
});

it('lo que no es editar es crear: sin id aunque se lo pasen', function () {
    $t = Livewire::test(EventForm::class, efArgs(['mode' => 'cualquiera', 'eventId' => 7, 'title' => 'x']))->call('save');

    expect(efDispatched($t, EventForm::SAVED)['data'])->toMatchArray(['mode' => 'create', 'id' => null]);
});

// ── Eliminar ───────────────────────────────────────────────────────────

it('eliminar pide confirmación con el diálogo de kore-ui y no devuelve nada todavía', function () {
    $t = Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 7, 'title' => 'Revisión']))
        ->call('delete')
        ->assertDispatched('kore:open')
        ->assertNotDispatched(EventForm::SAVED)
        ->assertNotDispatched('kore:close');

    $abrir = efDispatched($t, 'kore:open');

    expect($abrir['name'])->toBe('kore-confirm-dialog')
        ->and($abrir['arguments']['title'])->toBe('¿Eliminar «Revisión»?')
        ->and($abrir['arguments']['description'])->toBe('Esta acción no se puede deshacer.')
        ->and($abrir['arguments']['confirmText'])->toBe('Eliminar')
        ->and($abrir['arguments']['cancelText'])->toBe('Cancelar')
        ->and($abrir['arguments']['onConfirm'])->toBe(['method' => 'confirmDelete', 'params' => []])
        ->and($abrir['arguments']['callerRef'])->toBe($t->id())
        // Y el callback queda autorizado, o el diálogo no podría llamarlo.
        ->and($t->get('koreConfirmable'))->toBe(['confirmDelete']);
});

it('al confirmar devuelve mode delete con el id y cierra todo', function () {
    $t = Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 7, 'title' => 'Revisión']))
        ->call('delete')
        ->dispatch('kore:confirm-callback', method: 'confirmDelete', params: [], callerRef: 'otro')
        ->assertNotDispatched(EventForm::SAVED);   // el diálogo de otro componente no vale

    $t->dispatch('kore:confirm-callback', method: 'confirmDelete', params: [], callerRef: $t->id())
        ->assertDispatched('kore:close', force: true);

    expect(efDispatched($t, EventForm::SAVED))->toBe([
        'calendar' => EF_CAL,
        'data' => ['mode' => 'delete', 'id' => 7],
    ]);
});

it('crear no tiene nada que eliminar', function () {
    $t = Livewire::test(EventForm::class, efArgs())
        ->call('delete')
        ->assertNotDispatched('kore:open');

    // Ni siquiera con un aviso de confirmación forjado: no hay nada autorizado.
    $t->dispatch('kore:confirm-callback', method: 'confirmDelete', params: [], callerRef: $t->id())
        ->assertNotDispatched(EventForm::SAVED)
        ->assertNotDispatched('kore:close');
});

it('confirmDelete no se puede llamar desde el navegador: solo lo lanza el diálogo de confirmación', function () {
    $t = Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 7, 'title' => 'Revisión']));

    // Sin pasar por `delete()` (que abre el diálogo y autoriza el callback), eliminar no es posible.
    expect(fn () => $t->call('confirmDelete'))->toThrow(MethodNotFoundException::class);
    $t->assertNotDispatched(EventForm::SAVED);

    // Y un aviso de confirmación que no salió de `delete()` tampoco lo ejecuta.
    $t->dispatch('kore:confirm-callback', method: 'confirmDelete', params: [], callerRef: $t->id())
        ->assertNotDispatched(EventForm::SAVED);
});

it('cancelar cierra sin devolver nada', function () {
    Livewire::test(EventForm::class, efArgs())
        ->call('close')
        ->assertDispatched('kore:close')
        ->assertNotDispatched(EventForm::SAVED);
});

// ── Lo que el navegador no puede tocar ─────────────────────────────────

it('a quién responde, el modo y el evento están bloqueados', function (string $propiedad, mixed $valor) {
    Livewire::test(EventForm::class, efArgs(['mode' => 'edit', 'eventId' => 7]))->set($propiedad, $valor);
})->with([
    'calendario' => ['calendar', 'otro-calendario'],
    'modo' => ['mode', 'create'],
    'evento' => ['eventId', 99],
    'recursos' => ['resources', [['value' => '9', 'label' => 'x']]],
])->throws(CannotUpdateLockedPropertyException::class);

// ── Una ocurrencia de una serie ────────────────────────────────────────

/** Los argumentos con los que el calendario abre el formulario de una ocurrencia. */
function efOccurrence(array $over = []): array
{
    return efArgs(['mode' => 'edit', 'eventId' => 's', 'title' => 'Standup', 'instanceId' => 's:1760432400', 'recurring' => true, ...$over]);
}

it('guardar una ocurrencia avisa con su instanceId y NO cierra: el diálogo de alcance se apila encima', function () {
    $t = Livewire::test(EventForm::class, efOccurrence())
        ->set('title', 'Standup largo')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotDispatched('kore:close');

    expect(efDispatched($t, EventForm::SAVED))->toBe([
        'calendar' => EF_CAL,
        'data' => [
            'mode' => 'edit',
            'id' => 's',
            'title' => 'Standup largo',
            'allDay' => false,
            'start' => '2026-10-15 10:00:00',
            'end' => '2026-10-15 11:00:00',
            'resourceId' => null,
            'color' => null,
            'instanceId' => 's:1760432400',
        ],
    ]);
});

it('eliminar una ocurrencia no pide una confirmación aparte: el alcance ya lo es', function () {
    $t = Livewire::test(EventForm::class, efOccurrence())
        ->call('delete')
        ->assertNotDispatched('kore:open')
        ->assertNotDispatched('kore:close');

    expect(efDispatched($t, EventForm::SAVED))->toBe([
        'calendar' => EF_CAL,
        'data' => ['mode' => 'delete', 'id' => 's', 'instanceId' => 's:1760432400'],
    ]);
});

it('sin instanceId no es una ocurrencia, aunque se diga: nada que identificar', function () {
    $t = Livewire::test(EventForm::class, efOccurrence(['instanceId' => null]))
        ->set('title', 'x')
        ->call('save')
        ->assertDispatched('kore:close');

    expect(efDispatched($t, EventForm::SAVED)['data'])->not->toHaveKey('instanceId');

    // Y crear nunca lo es.
    $crear = Livewire::test(EventForm::class, efArgs(['instanceId' => 's:1', 'recurring' => true]))->set('title', 'x')->call('save');

    expect(efDispatched($crear, EventForm::SAVED)['data'])->not->toHaveKey('instanceId');
    $crear->assertDispatched('kore:close');
});

it('la ocurrencia que se edita está bloqueada', function (string $propiedad, mixed $valor) {
    Livewire::test(EventForm::class, efOccurrence())->set($propiedad, $valor);
})->with([
    'instanceId' => ['instanceId', 's:1'],
    'recurring' => ['recurring', false],
])->throws(CannotUpdateLockedPropertyException::class);
