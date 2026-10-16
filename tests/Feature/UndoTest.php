<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use KoreUi\Calendar\Interaction\Events\EventMoved;
use KoreUi\Calendar\Interaction\Events\EventResized;
use KoreUi\Calendar\Tests\Feature\Fixtures\FormCalendar as Cal;
use KoreUi\Calendar\Tests\Feature\Fixtures\InteractiveCalendar;
use KoreUi\Calendar\Time\Marker;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| Deshacer el último cambio: opt-in con `undoable()`, un toast con la acción
| «Deshacer» tras un movimiento o un cambio de duración aceptados, y
| `undoLastChange()` que lo repite al revés con el MISMO hook de persistencia.
|
| Los eventos de siempre (InteractiveCalendar::defaultEvents), semana del 12 al 18 de
| octubre de 2026 en Europe/Madrid:
|   1 «Revisión»  mié 14, 10:00-11:30
|   2 «Congreso»  jue 15 → vie 16 (día completo, [15, 17))
|   3 «Bloqueo»   vie 16, 15:00-16:00, overlap false
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
    Cal::fresh();
    Cal::$undoable = true;   // también lo hace editable()
});

afterEach(fn () => CarbonImmutable::setTestNow());

function udTs(string $wall): int
{
    return Marker::parse($wall)->ts;
}

/** El toast que se envió, o null. */
function udToast($testable): ?array
{
    $toast = collect(data_get($testable->effects, 'dispatches'))->firstWhere('name', 'kore:toast');

    return $toast['params']['toast'] ?? null;
}

function udIso(?DateTimeInterface $date): ?string
{
    return $date?->format('c');
}

// ── Ofrecer ────────────────────────────────────────────────────────────

it('sin undoable() mover no ofrece deshacer ni guarda nada', function () {
    Cal::$undoable = false;
    Cal::$config = ['interaction.editable' => true];

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    $t->assertReturned(fn (array $r) => $r['ok'] === true)->assertNotDispatched('kore:toast');

    expect($t->get('koreLastChange'))->toBeNull();

    // Y deshacer no tiene nada que deshacer.
    $t->call('undoLastChange');
    expect(Cal::$moved)->toHaveCount(1);
});

it('con undoable() un movimiento aceptado ofrece un toast breve con la acción Deshacer', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    expect(udToast($t))->toMatchArray([
        'type' => 'info',
        'title' => '«Revisión» movido al 15 de octubre de 2026 14:00',
        'actions' => [['label' => 'Deshacer', 'method' => 'undoLastChange', 'params' => []]],
    ])
        // El toast es del calendario: la acción se llama sobre él.
        ->and(udToast($t)['reference'])->toBe($t->id());
});

it('el aria-live sigue anunciando el movimiento aunque haya toast', function () {
    Livewire::test(Cal::class)
        ->call('moveEvent', 1, udTs('2026-10-15 14:00'), false)
        ->assertReturned(['ok' => true, 'message' => '«Revisión» movido al 15 de octubre de 2026 14:00']);
});

it('cambiar la duración también lo ofrece', function () {
    $t = Livewire::test(Cal::class)->call('resizeEvent', 1, udTs('2026-10-14 12:00'));

    expect(udToast($t))->toMatchArray(['type' => 'info', 'actions' => [['label' => 'Deshacer', 'method' => 'undoLastChange', 'params' => []]]]);
});

it('el texto de la acción sale de la configuración', function () {
    config(['kore-calendar.translations.undo' => 'Undo']);

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    expect(udToast($t)['actions'][0]['label'])->toBe('Undo');
});

it('un cambio rechazado, o que no cambia nada, no ofrece deshacer', function () {
    Cal::$persistResult = false;
    $rechazado = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    expect(udToast($rechazado)['type'])->toBe('error')
        ->and($rechazado->get('koreLastChange'))->toBeNull();

    Cal::$persistResult = null;
    $igual = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-14 10:00'), false);

    expect($igual->get('koreLastChange'))->toBeNull()
        ->and(udToast($igual))->toBeNull();
});

it('el estado que guarda son solo escalares: Livewire lo lleva de una petición a otra', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    expect($t->get('koreLastChange'))->toBe([
        'type' => 'move',
        'id' => 1,
        'start' => udTs('2026-10-14 10:00'),
        'end' => udTs('2026-10-14 11:30'),
        'allDay' => false,
        'resourceIds' => [],
        'startInstant' => CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Madrid')->getTimestamp(),
        'endInstant' => CarbonImmutable::parse('2026-10-14 11:30', 'Europe/Madrid')->getTimestamp(),
    ]);
});

it('el navegador no puede escribir el estado de deshacer', function () {
    Livewire::test(Cal::class)->set('koreLastChange', ['type' => 'move', 'id' => 3]);
})->throws(CannotUpdateLockedPropertyException::class);

// ── Deshacer ───────────────────────────────────────────────────────────

it('deshacer un movimiento lo persiste al revés con persistMove', function () {
    Event::fake([EventMoved::class]);

    $t = Livewire::test(Cal::class)
        ->call('moveEvent', 1, udTs('2026-10-15 14:00'), false)
        ->call('undoLastChange');

    expect(Cal::$moved)->toHaveCount(2);
    [$event, $mutation] = Cal::$moved[1];

    // El evento actual, y la mutación lleva el evento a como estaba.
    expect($event->id)->toBe(1)
        ->and(udIso($mutation->start))->toBe('2026-10-14T10:00:00+02:00')
        ->and(udIso($mutation->end))->toBe('2026-10-14T11:30:00+02:00')
        ->and($mutation->allDay)->toBeFalse()
        ->and((string) $mutation->startMarker)->toBe('2026-10-14 10:00:00')
        ->and((string) $mutation->endMarker)->toBe('2026-10-14 11:30:00')
        // Y sus `previous*` son lo de ahora (después de mover).
        ->and(udIso($mutation->previousStart))->toBe('2026-10-15T14:00:00+02:00')
        ->and((string) $mutation->previousStartMarker)->toBe('2026-10-15 14:00:00')
        ->and($mutation->deltaSeconds)->toBe(-(24 * 3600 + 4 * 3600));

    expect(Cal::$resized)->toBe([])
        ->and($t->get('koreLastChange'))->toBeNull()
        ->and(udToast($t))->toMatchArray(['type' => 'success', 'title' => 'Cambio deshecho']);

    // Para quien audita, deshacer es un movimiento más.
    Event::assertDispatchedTimes(EventMoved::class, 2);
});

it('el calendario se repinta con el evento en su sitio', function () {
    // events() devuelve siempre lo de siempre: lo que se comprueba es que se repinta.
    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false)->call('undoLastChange');

    expect(data_get($t->effects, 'html'))->not->toBeNull();
});

it('solo se puede deshacer una vez', function () {
    $t = Livewire::test(Cal::class)
        ->call('moveEvent', 1, udTs('2026-10-15 14:00'), false)
        ->call('undoLastChange')
        ->call('undoLastChange');

    // Un movimiento y un solo deshacer.
    expect(Cal::$moved)->toHaveCount(2)
        ->and(udToast($t))->toBeNull()
        ->and($t->get('koreLastChange'))->toBeNull();
});

it('sin nada que deshacer no pasa nada ni se repinta', function () {
    $t = Livewire::test(Cal::class)->call('undoLastChange');

    expect(Cal::$moved)->toBe([])
        ->and(data_get($t->effects, 'html'))->toBeNull()
        ->and(udToast($t))->toBeNull();
});

it('solo se deshace el último cambio', function () {
    Cal::$events = [
        ['id' => 1, 'title' => 'Revisión', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:30'],
        ['id' => 4, 'title' => 'Otro', 'start' => '2026-10-16 15:00', 'end' => '2026-10-16 16:00'],
    ];

    $t = Livewire::test(Cal::class)
        ->call('moveEvent', 1, udTs('2026-10-15 14:00'), false)
        ->call('moveEvent', 4, udTs('2026-10-17 15:00'), false)   // el otro evento
        ->call('undoLastChange');

    expect(Cal::$moved)->toHaveCount(3)
        ->and(Cal::$moved[2][0]->id)->toBe(4)
        ->and(udIso(Cal::$moved[2][1]->start))->toBe('2026-10-16T15:00:00+02:00');

    $t->call('undoLastChange');
    expect(Cal::$moved)->toHaveCount(3);
});

it('deshacer un cambio de duración usa persistResize', function () {
    $t = Livewire::test(Cal::class)
        ->call('resizeEvent', 1, udTs('2026-10-14 12:00'))
        ->call('undoLastChange');

    expect(Cal::$resized)->toHaveCount(2)
        ->and(Cal::$moved)->toBe([]);

    $mutation = Cal::$resized[1][1];

    expect(udIso($mutation->start))->toBe('2026-10-14T10:00:00+02:00')
        ->and(udIso($mutation->end))->toBe('2026-10-14T11:30:00+02:00')
        ->and($mutation->deltaSeconds)->toBe(-1800)
        ->and(udToast($t)['title'])->toBe('Cambio deshecho');
});

it('deshacer un cambio por el borde inicial lo devuelve', function () {
    Livewire::test(Cal::class)
        ->call('resizeEvent', 1, udTs('2026-10-14 09:00'), true)
        ->call('undoLastChange');

    $mutation = Cal::$resized[1][1];

    expect(udIso($mutation->start))->toBe('2026-10-14T10:00:00+02:00')
        ->and(udIso($mutation->end))->toBe('2026-10-14T11:30:00+02:00')
        ->and($mutation->deltaSeconds)->toBe(3600);
});

it('mover un día completo a una hora y deshacerlo devuelve sus días, no la duración por defecto', function () {
    // Sin el «Bloqueo» (overlap false) del viernes, que choca con los dos días del «Congreso».
    Cal::$events = array_slice(InteractiveCalendar::defaultEvents(), 0, 2);

    Livewire::test(Cal::class)
        ->call('moveEvent', 2, udTs('2026-10-15 09:00'), false)
        ->call('undoLastChange');

    // «Congreso» (día completo, [15, 17)) pasó a durar una hora; deshacer le devuelve los dos días.
    $mutation = Cal::$moved[1][1];

    expect($mutation->allDay)->toBeTrue()
        ->and($mutation->startDate())->toBe('2026-10-15')
        ->and($mutation->endDate())->toBe('2026-10-17')
        ->and(udIso($mutation->start))->toBe('2026-10-15T00:00:00+02:00')
        ->and(udIso($mutation->end))->toBe('2026-10-17T00:00:00+02:00');
});

it('un evento sin fin vuelve a quedarse sin fin', function () {
    Cal::$events = [['id' => 5, 'title' => 'Sin fin', 'start' => '2026-10-14 10:00']];

    Livewire::test(Cal::class)
        ->call('moveEvent', 5, udTs('2026-10-15 14:00'), false)
        ->call('undoLastChange');

    $mutation = Cal::$moved[1][1];

    // Una columna ends_at NULL no pasa a tener un valor por deshacer.
    expect($mutation->end)->toBeNull()
        ->and(udIso($mutation->start))->toBe('2026-10-14T10:00:00+02:00')
        ->and(udIso($mutation->endInstant))->toBe('2026-10-14T11:00:00+02:00');
});

it('deshacer un cambio de recurso devuelve el recurso', function () {
    Cal::$resources = [['id' => 10, 'title' => 'A'], ['id' => 20, 'title' => 'B']];
    Cal::$config = ['interaction.resource_editable' => true];
    Cal::$events = [['id' => 1, 'title' => 'Reunión', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'resourceIds' => [10]]];

    Livewire::test(Cal::class)
        ->call('changeView', 'resource-week')
        ->call('moveEvent', 1, udTs('2026-10-14 10:00'), false, 20, 10)
        ->call('undoLastChange');

    expect(Cal::$moved[0][1]->resourceIds)->toBe([20])
        ->and(Cal::$moved)->toHaveCount(2)
        ->and(Cal::$moved[1][1]->resourceIds)->toBe([10]);
});

it('conserva el instante exacto de la hora repetida del otoño', function () {
    // El domingo 25 de octubre de 2026 la hora 02:30 ocurre dos veces. El evento empieza en la SEGUNDA (+01:00).
    Cal::$events = [['id' => 1, 'title' => 'Turno', 'start' => '2026-10-25T02:30:00+01:00', 'end' => '2026-10-25T03:30:00+01:00']];

    Livewire::test(Cal::class)
        ->set('date', '2026-10-25')
        ->call('moveEvent', 1, udTs('2026-10-25 05:00'), false)
        ->call('undoLastChange');

    $mutation = Cal::$moved[1][1];

    expect(udIso($mutation->start))->toBe('2026-10-25T02:30:00+01:00')
        ->and(udIso($mutation->end))->toBe('2026-10-25T03:30:00+01:00');
});

// ── Deshacer: rechazos ─────────────────────────────────────────────────

it('si el hook rechaza el deshacer, sale el motivo y no se puede reintentar', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    Cal::$persistResult = 'La reserva ya está confirmada';
    $t->call('undoLastChange');

    expect(udToast($t))->toMatchArray([
        'type' => 'error',
        'title' => 'No se pudo deshacer el cambio',
        'description' => 'La reserva ya está confirmada',
    ])
        ->and($t->get('koreLastChange'))->toBeNull()
        // No se repinta: nada cambió.
        ->and(data_get($t->effects, 'html'))->toBeNull();

    Cal::$persistResult = null;
    $t->call('undoLastChange');
    expect(Cal::$moved)->toHaveCount(2);
});

it('si el evento ya no está en la vista no se deshace nada', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    // Borrado en otra pestaña.
    Cal::$events = [InteractiveCalendar::defaultEvents()[1]];
    $t->call('undoLastChange');

    expect(Cal::$moved)->toHaveCount(1)
        ->and(udToast($t))->toMatchArray(['type' => 'error', 'title' => 'No se pudo deshacer el cambio', 'description' => 'El evento ya no está disponible'])
        // Se repinta: la vista estaba desfasada.
        ->and(data_get($t->effects, 'html'))->not->toBeNull();
});

it('si el usuario cambió de semana, el evento tampoco está en la vista', function () {
    $t = Livewire::test(Cal::class)
        ->call('moveEvent', 1, udTs('2026-10-15 14:00'), false)
        ->call('next')
        ->call('undoLastChange');

    expect(Cal::$moved)->toHaveCount(1)
        ->and(udToast($t)['description'])->toBe('El evento ya no está disponible');
});

it('se valida como cualquier cambio: si otro evento ocupó el sitio, se rechaza', function () {
    Cal::$config = ['interaction.event_overlap' => false];
    Cal::$events = [
        ['id' => 1, 'title' => 'Revisión', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00'],
        ['id' => 4, 'title' => 'Intruso', 'start' => '2026-10-15 09:00', 'end' => '2026-10-15 10:00'],
    ];

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    // Mientras tanto, alguien crea un evento donde estaba el 1.
    Cal::$events[] = ['id' => 6, 'title' => 'Nuevo', 'start' => '2026-10-14 10:30', 'end' => '2026-10-14 11:30'];
    $t->call('undoLastChange');

    expect(Cal::$moved)->toHaveCount(1)
        ->and(udToast($t)['description'])->toBe('Se solapa con «Nuevo»');
});

it('un evento que dejó de ser editable no se deshace', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    Cal::$events = [
        ['id' => 1, 'title' => 'Revisión', 'start' => '2026-10-15 14:00', 'end' => '2026-10-15 15:30', 'editable' => false],
    ];
    $t->call('undoLastChange');

    expect(Cal::$moved)->toHaveCount(1)
        ->and(udToast($t)['description'])->toBe('Este evento no se puede editar');
});

it('si el evento ya está donde estaba, no hay nada que guardar', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    // Otro arrastre lo devolvió a su sitio: events() ya lo da en su posición original.
    Cal::$events = InteractiveCalendar::defaultEvents();
    $t->call('undoLastChange');

    expect(Cal::$moved)->toHaveCount(1)
        ->and(udToast($t))->toBeNull()
        ->and($t->get('koreLastChange'))->toBeNull();
});

it('una excepción del hook al deshacer se reporta y sale el mensaje genérico', function () {
    Illuminate\Support\Facades\Exceptions::fake();

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, udTs('2026-10-15 14:00'), false);

    Cal::$persistResult = fn () => throw new RuntimeException('se cayó');
    $t->call('undoLastChange');

    Illuminate\Support\Facades\Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'se cayó');
    expect(udToast($t))->toMatchArray(['title' => 'No se pudo deshacer el cambio', 'description' => 'No se pudo guardar el cambio']);
});

it('el hook de deshacer corre dentro de una transacción', function () {
    $nivel = null;
    Cal::$persistResult = function () use (&$nivel) {
        $nivel = rescue(fn () => Illuminate\Support\Facades\DB::transactionLevel(), -1, false);
    };

    Livewire::test(Cal::class)
        ->call('moveEvent', 1, udTs('2026-10-15 14:00'), false)
        ->call('undoLastChange');

    expect($nivel)->toBe(1);
});

it('al deshacer se dispara el mismo evento de Laravel que al hacerlo, según el tipo', function () {
    Event::fake([EventMoved::class, EventResized::class]);

    Livewire::test(Cal::class)
        ->call('resizeEvent', 1, udTs('2026-10-14 12:00'))
        ->call('undoLastChange');

    Event::assertDispatchedTimes(EventResized::class, 2);
    Event::assertNotDispatched(EventMoved::class);
});
