<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Interaction\EventMutation;
use KoreUi\Calendar\Interaction\Events\EventMoved;
use KoreUi\Calendar\Interaction\Events\EventResized;
use KoreUi\Calendar\Tests\Feature\Fixtures\InteractiveCalendar as Cal;
use KoreUi\Calendar\Tests\Feature\Fixtures\UnimplementedCalendar;
use Livewire\Livewire;

require_once __DIR__.'/../Interaction/helpers.php';

/*
| Las acciones que llegan del navegador: moveEvent, resizeEvent, selectRange.
| La semana de los tests es la del lunes 12 al domingo 18 de octubre de 2026, en
| Europe/Madrid. Los eventos de siempre (InteractiveCalendar::defaultEvents):
|   1 «Revisión»  mié 14, 10:00-11:30
|   2 «Congreso»  jue 15 → vie 16 (día completo, [15, 17))
|   3 «Bloqueo»   vie 16, 15:00-16:00, overlap false
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
    Cal::fresh();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function ixTs(string $wall): int
{
    return ixMarker($wall)->ts;
}

/** El toast de error que se envió, o null. */
function ixToast($testable): ?array
{
    $toast = collect(data_get($testable->effects, 'dispatches'))->firstWhere('name', 'kore:toast');

    return $toast['params']['toast'] ?? null;
}

// ── moveEvent: aceptado ────────────────────────────────────────────────

it('mover un evento persiste, dispara EventMoved y devuelve el anuncio para aria-live', function () {
    Event::fake([EventMoved::class]);

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);

    $t->assertReturned(['ok' => true, 'message' => '«Revisión» movido al 15 de octubre de 2026 14:00']);

    expect(Cal::$moved)->toHaveCount(1);
    [$event, $mutation] = Cal::$moved[0];

    expect($event)->toBeInstanceOf(CalendarEvent::class)
        ->and($event->id)->toBe(1)
        ->and($mutation)->toBeInstanceOf(EventMutation::class)
        ->and(ixIso($mutation->start))->toBe('2026-10-15T14:00:00+02:00')
        ->and(ixIso($mutation->end))->toBe('2026-10-15T15:30:00+02:00')
        ->and($mutation->startDate())->toBe('2026-10-15');

    Event::assertDispatched(EventMoved::class, fn (EventMoved $e) => $e->calendarClass === Cal::class
        && $e->event->id === 1
        && $e->mutation === $mutation
        && ixIso($e->mutation->start) === '2026-10-15T14:00:00+02:00');
    Event::assertNotDispatched(EventResized::class);

    // El éxito se anuncia por aria-live: no hay toast.
    $t->assertNotDispatched('kore:toast');
});

it('un evento de día completo se anuncia con la fecha sin hora', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', 2, ixTs('2026-10-13'), true);

    $t->assertReturned(['ok' => true, 'message' => '«Congreso» movido al 13 de octubre de 2026']);

    $mutation = Cal::$moved[0][1];

    expect($mutation->startDate())->toBe('2026-10-13')
        ->and($mutation->endDate())->toBe('2026-10-15')
        ->and($mutation->allDay)->toBeTrue();
});

it('mover entre modalidades aplica las reglas de FullCalendar', function () {
    Livewire::test(Cal::class)->call('moveEvent', 2, ixTs('2026-10-15 09:00'), false);

    $m = Cal::$moved[0][1];

    // El día completo [15, 17) arrastrado a las 09:00: la duración por defecto, 1 h.
    expect($m->allDay)->toBeFalse()
        ->and(ixIso($m->start))->toBe('2026-10-15T09:00:00+02:00')
        ->and(ixIso($m->end))->toBe('2026-10-15T10:00:00+02:00');
});

it('lo que manda el cliente se vuelve a ajustar al snap en el servidor', function () {
    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:07:20'), false);

    expect(ixIso(Cal::$moved[0][1]->start))->toBe('2026-10-15T14:00:00+02:00');
});

it('el id llega por JSON como string o como número: es el mismo evento', function () {
    Livewire::test(Cal::class)->call('moveEvent', '1', ixTs('2026-10-15 14:00'), false);

    expect(Cal::$moved)->toHaveCount(1);
});

it('soltar el evento donde estaba no es un rechazo ni persiste nada', function () {
    Event::fake([EventMoved::class]);

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-14 10:00'), false);

    $t->assertReturned(['ok' => true, 'message' => ''])->assertNotDispatched('kore:toast');
    expect(Cal::$moved)->toBe([]);
    Event::assertNotDispatched(EventMoved::class);
});

it('el hook corre dentro de una transacción cuando hay base de datos', function () {
    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);

    expect(Cal::$moved[0][2])->toBe(1)
        // Y se cierra al terminar.
        ->and(DB::transactionLevel())->toBe(0);
});

it('sin base de datos configurada el hook corre igual, sin transacción', function () {
    // Una conexión por defecto que no existe: DB::transaction() lanzaría.
    config(['database.default' => 'no-existe']);

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);

    $t->assertReturned(fn (array $r) => $r['ok'] === true);
    expect(Cal::$moved)->toHaveCount(1)
        ->and(Cal::$moved[0][2])->toBe(-1);
});

it('con una conexión que no se puede abrir tampoco falla', function () {
    config([
        'database.default' => 'caida',
        'database.connections.caida' => ['driver' => 'sqlite', 'database' => '/ruta/que/no/existe/db.sqlite'],
    ]);

    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false)
        ->assertReturned(fn (array $r) => $r['ok'] === true);
});

// ── moveEvent: rechazado ───────────────────────────────────────────────

it('un id que no existe se rechaza con toast y sin persistir', function () {
    Event::fake([EventMoved::class]);

    $t = Livewire::test(Cal::class)->call('moveEvent', 999, ixTs('2026-10-15 14:00'), false);

    $t->assertReturned(['ok' => false, 'message' => 'El evento ya no está disponible']);

    expect(ixToast($t))->toMatchArray(['type' => 'error', 'title' => 'No se pudo mover el evento', 'description' => 'El evento ya no está disponible'])
        ->and(Cal::$moved)->toBe([]);
    Event::assertNotDispatched(EventMoved::class);
});

it('un rechazo no repinta el calendario (nada cambió), salvo si el evento ya no existe: la vista está desfasada', function () {
    $repintado = fn ($t) => data_get($t->effects, 'html') !== null;

    // Rechazo por una regla: no hay nada que repintar.
    $regla = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-16 15:30'), false);
    expect($regla->effects['returns'][0]['ok'])->toBeFalse()
        ->and($repintado($regla))->toBeFalse();

    // El evento ya no existe: se repinta para que desaparezca de la pantalla.
    $viejo = Livewire::test(Cal::class)->call('moveEvent', 999, ixTs('2026-10-15 14:00'), false);
    expect($repintado($viejo))->toBeTrue();

    // Aceptado: se repinta con la posición nueva.
    $ok = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);
    expect($repintado($ok))->toBeTrue();
});

it('IDOR: un evento que existe pero que events() no devuelve para esta vista no se puede mover', function () {
    // El usuario solo ve el 1 (su scope). El 77 existe en la base de datos de otro.
    Cal::$events = [Cal::defaultEvents()[0]];

    $t = Livewire::test(Cal::class)->call('moveEvent', 77, ixTs('2026-10-15 14:00'), false);

    $t->assertReturned(['ok' => false, 'message' => 'El evento ya no está disponible']);
    expect(Cal::$moved)->toBe([]);
});

it('IDOR: un evento de otra semana no se puede mover desde esta vista', function () {
    Cal::$events = [
        ...Cal::defaultEvents(),
        ['id' => 55, 'title' => 'Otra semana', 'start' => '2026-11-20 10:00', 'end' => '2026-11-20 11:00'],
    ];

    Livewire::test(Cal::class)
        ->call('moveEvent', 55, ixTs('2026-10-15 14:00'), false)
        ->assertReturned(['ok' => false, 'message' => 'El evento ya no está disponible']);

    // Pero sí desde la vista donde está dibujado.
    Livewire::test(Cal::class)->set('date', '2026-11-20')
        ->call('moveEvent', 55, ixTs('2026-11-20 14:00'), false)
        ->assertReturned(fn (array $r) => $r['ok'] === true);
});

it('vuelve a llamar a events() en cada acción: un evento borrado deja de ser movible', function () {
    $t = Livewire::test(Cal::class);
    $antes = Cal::$eventCalls;

    Cal::$events = [Cal::defaultEvents()[1]];   // el 1 se borró en otra pestaña

    $t->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false)
        ->assertReturned(['ok' => false, 'message' => 'El evento ya no está disponible']);

    expect(Cal::$eventCalls)->toBeGreaterThan($antes);
});

it('un calendario que no es editable rechaza mover', function () {
    Cal::$config = ['interaction.editable' => false];
    Event::fake([EventMoved::class]);

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);

    $t->assertReturned(['ok' => false, 'message' => 'Este evento no se puede editar']);

    expect(ixToast($t)['title'])->toBe('No se pudo mover el evento')
        ->and(Cal::$moved)->toBe([]);
    Event::assertNotDispatched(EventMoved::class);
});

it('el evento manda sobre el calendario: uno editable:false en un calendario abierto, y al revés', function () {
    Cal::$events = [
        ['id' => 1, 'title' => 'Cerrado', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'editable' => false],
    ];
    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false)
        ->assertReturned(['ok' => false, 'message' => 'Este evento no se puede editar']);

    Cal::fresh();
    Cal::$config = ['interaction.editable' => false];
    Cal::$events = [
        ['id' => 1, 'title' => 'Abierto', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'editable' => true],
    ];
    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false)
        ->assertReturned(fn (array $r) => $r['ok'] === true);
});

it('con event_overlap false rechaza el solape y nombra el evento con el que choca', function () {
    Cal::$config = ['interaction.event_overlap' => false];
    // Sin el «Congreso» de día completo, que ocupa todo el viernes.
    Cal::$events = [Cal::defaultEvents()[0], Cal::defaultEvents()[2]];

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-16 15:30'), false);

    $t->assertReturned(['ok' => false, 'message' => 'Se solapa con «Bloqueo»']);
    expect(ixToast($t)['description'])->toBe('Se solapa con «Bloqueo»')
        ->and(Cal::$moved)->toBe([]);
});

it('un evento con overlap false bloquea aunque el calendario permita solapes', function () {
    // El 3 («Bloqueo») es overlap:false. Sin ninguna configuración global.
    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-16 15:30'), false)
        ->assertReturned(['ok' => false, 'message' => 'Se solapa con «Bloqueo»']);

    // Justo al terminar el bloqueo ya no se pisan: semiabierto.
    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-16 16:00'), false)
        ->assertReturned(fn (array $r) => $r['ok'] === true);
});

it('los eventos del mismo groupId no colisionan entre sí, y sin grupo sí', function () {
    Cal::$config = ['interaction.event_overlap' => false];
    Cal::$events = [
        ['id' => 10, 'title' => 'Turno A', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'groupId' => 'turno'],
        ['id' => 11, 'title' => 'Turno B', 'start' => '2026-10-14 14:00', 'end' => '2026-10-14 15:00', 'groupId' => 'turno'],
        ['id' => 12, 'title' => 'Suelto', 'start' => '2026-10-15 14:00', 'end' => '2026-10-15 15:00'],
    ];

    // Encima del hermano del grupo: permitido.
    Livewire::test(Cal::class)
        ->call('moveEvent', 10, ixTs('2026-10-14 14:30'), false)
        ->assertReturned(fn (array $r) => $r['ok'] === true);

    // Control: el mismo gesto sobre uno sin grupo se rechaza.
    Livewire::test(Cal::class)
        ->call('moveEvent', 10, ixTs('2026-10-15 14:30'), false)
        ->assertReturned(['ok' => false, 'message' => 'Se solapa con «Suelto»']);
});

it('valid_range: el rango entero tiene que caber', function () {
    Cal::$config = ['interaction.valid_range' => ['start' => '2026-10-13', 'end' => '2026-10-16']];

    // Dentro.
    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false)
        ->assertReturned(fn (array $r) => $r['ok'] === true);

    // Fuera por el principio, por el final, y empezando dentro pero acabando fuera.
    foreach (['2026-10-12 10:00', '2026-10-17 10:00', '2026-10-16 23:00'] as $destino) {
        $t = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs($destino), false);

        $t->assertReturned(['ok' => false, 'message' => 'Fuera del rango permitido']);
        expect(ixToast($t)['title'])->toBe('No se pudo mover el evento');
    }

    expect(Cal::$moved)->toHaveCount(1);
});

it('un marker absurdo se rechaza antes de llegar a ningún cálculo', function () {
    foreach ([PHP_INT_MAX, PHP_INT_MIN, -62_135_596_801] as $ts) {
        Livewire::test(Cal::class)
            ->call('moveEvent', 1, $ts, false)
            ->assertReturned(['ok' => false, 'message' => 'Fuera del rango permitido']);
    }

    expect(Cal::$moved)->toBe([]);
});

// ── moveEvent: recursos ────────────────────────────────────────────────

function ixRooms(): void
{
    Cal::$resources = [
        ['id' => 'a', 'title' => 'Sala A'],
        ['id' => 'b', 'title' => 'Sala B'],
        ['id' => 'c', 'title' => 'Sala C'],
        ['id' => 7, 'title' => 'Sala 7'],
    ];
    Cal::$events = [
        ['id' => 1, 'title' => 'Solo en A', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'resourceIds' => ['a']],
        ['id' => 2, 'title' => 'En A y B', 'start' => '2026-10-14 12:00', 'end' => '2026-10-14 13:00', 'resourceIds' => ['a', 'b']],
        ['id' => 3, 'title' => 'Sin sala', 'start' => '2026-10-14 16:00', 'end' => '2026-10-14 17:00'],
    ];
}

it('cambiar de recurso sustituye el de origen y no toca los demás', function () {
    ixRooms();

    $t = Livewire::test(Cal::class)->call('moveEvent', 2, ixTs('2026-10-14 12:00'), false, 'c', 'a');

    $t->assertReturned(fn (array $r) => $r['ok'] === true);

    $m = Cal::$moved[0][1];

    expect($m->resourceIds)->toBe(['c', 'b'])
        ->and($m->previousResourceIds)->toBe(['a', 'b'])
        ->and($m->isResourceChange())->toBeTrue()
        // Solo cambió de sala: la hora exacta es la misma.
        ->and($m->startMarker->toDateTimeString())->toBe('2026-10-14 12:00:00');
});

it('un evento con un solo recurso lo cambia sin fromResourceId, y a la vez puede cambiar de hora', function () {
    ixRooms();

    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-14 15:00'), false, 'b');

    expect(Cal::$moved[0][1]->resourceIds)->toBe(['b'])
        ->and(ixIso(Cal::$moved[0][1]->start))->toBe('2026-10-14T15:00:00+02:00');
});

it('un evento sin recurso se asigna al soltarlo en una columna', function () {
    ixRooms();

    Livewire::test(Cal::class)->call('moveEvent', 3, ixTs('2026-10-14 16:00'), false, 'c');

    expect(Cal::$moved[0][1]->resourceIds)->toBe(['c']);
});

it('el recurso de destino llega como string pero se guarda con el tipo del ResourceSet', function () {
    ixRooms();

    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-14 10:00'), false, '7');

    expect(Cal::$moved[0][1]->resourceIds)->toBe([7]);
});

it('un recurso de destino que no existe se rechaza', function () {
    ixRooms();

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-14 10:00'), false, 'fantasma');

    $t->assertReturned(['ok' => false, 'message' => 'El recurso de destino no existe']);
    expect(Cal::$moved)->toBe([])
        ->and(ixToast($t)['title'])->toBe('No se pudo mover el evento');
});

it('con un recurso de destino y un calendario sin recursos, también se rechaza', function () {
    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-14 10:00'), false, 'a')
        ->assertReturned(['ok' => false, 'message' => 'El recurso de destino no existe']);
});

it('un evento con varios recursos sin fromResourceId es ambiguo: se rechaza', function () {
    ixRooms();

    Livewire::test(Cal::class)
        ->call('moveEvent', 2, ixTs('2026-10-14 12:00'), false, 'c')
        ->assertReturned(['ok' => false, 'message' => 'El cambio no es válido']);

    // Un origen que el evento no tiene, igual.
    Livewire::test(Cal::class)
        ->call('moveEvent', 2, ixTs('2026-10-14 12:00'), false, 'c', 'z')
        ->assertReturned(['ok' => false, 'message' => 'El cambio no es válido']);

    expect(Cal::$moved)->toBe([]);
});

it('resource_editable false impide cambiar de recurso pero no de hora', function () {
    ixRooms();
    Cal::$config = ['interaction.resource_editable' => false];

    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-14 10:00'), false, 'b')
        ->assertReturned(['ok' => false, 'message' => 'Este evento no se puede cambiar de recurso']);

    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-14 15:00'), false, 'a')
        ->assertReturned(fn (array $r) => $r['ok'] === true);

    expect(Cal::$moved)->toHaveCount(1);
});

it('los solapes con recursos solo cuentan en la sala de destino', function () {
    ixRooms();
    Cal::$config = ['interaction.event_overlap' => false];

    // El 1 (sala A, 10-11) a las 12:30 se pisaría con el 2 (A y B, 12-13)…
    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-14 12:30'), false, 'a')
        ->assertReturned(['ok' => false, 'message' => 'Se solapa con «En A y B»']);

    // …pero en la sala C no hay nadie.
    Livewire::test(Cal::class)
        ->call('moveEvent', 1, ixTs('2026-10-14 12:30'), false, 'c')
        ->assertReturned(fn (array $r) => $r['ok'] === true);
});

// ── Los hooks ──────────────────────────────────────────────────────────

it('un hook que devuelve true o null acepta; false rechaza con el mensaje genérico; un texto, con ese motivo', function () {
    foreach ([null, true] as $ok) {
        Cal::fresh();
        Cal::$hookResult = $ok;

        Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false)
            ->assertReturned(fn (array $r) => $r['ok'] === true);
    }

    Cal::fresh();
    Cal::$hookResult = false;

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);

    $t->assertReturned(['ok' => false, 'message' => 'No se pudo guardar el cambio']);
    expect(ixToast($t))->toMatchArray(['title' => 'No se pudo mover el evento', 'description' => 'No se pudo guardar el cambio']);

    Cal::fresh();
    Cal::$hookResult = 'La sala está en mantenimiento';

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);

    $t->assertReturned(['ok' => false, 'message' => 'La sala está en mantenimiento']);
    expect(ixToast($t)['description'])->toBe('La sala está en mantenimiento');
});

it('un hook que rechaza no dispara EventMoved', function () {
    Event::fake([EventMoved::class]);
    Cal::$hookResult = 'No';

    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);

    Event::assertNotDispatched(EventMoved::class);
});

it('si el hook rechaza o lanza, lo que escribió antes se deshace', function () {
    Schema::create('ix_filas', function ($t) {
        $t->increments('id');
        $t->string('valor');
    });

    $escribe = fn (mixed $despues) => function () use ($despues) {
        DB::table('ix_filas')->insert(['valor' => 'a medias']);

        return $despues instanceof Throwable ? throw $despues : $despues;
    };

    Exceptions::fake();

    Cal::$hookResult = $escribe(false);
    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);
    expect(DB::table('ix_filas')->count())->toBe(0);

    Cal::$hookResult = $escribe('No');
    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);
    expect(DB::table('ix_filas')->count())->toBe(0);

    Cal::$hookResult = $escribe(new RuntimeException('boom'));
    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);
    expect(DB::table('ix_filas')->count())->toBe(0);

    // Y si acepta, se queda.
    Cal::$hookResult = $escribe(null);
    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);
    expect(DB::table('ix_filas')->count())->toBe(1);
});

it('una excepción en el hook no se traga: se reporta, se rechaza con el mensaje genérico y no dispara el evento', function () {
    Exceptions::fake();
    Event::fake([EventMoved::class]);
    Cal::$hookResult = fn () => throw new RuntimeException('la base de datos se cayó');

    $t = Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);

    $t->assertReturned(['ok' => false, 'message' => 'No se pudo guardar el cambio']);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'la base de datos se cayó');
    Event::assertNotDispatched(EventMoved::class);

    expect(ixToast($t))->toMatchArray(['type' => 'error', 'title' => 'No se pudo mover el evento'])
        // El mensaje interno no llega al usuario.
        ->and(json_encode(ixToast($t)))->not->toContain('base de datos');
});

it('una ValidationException del hook es una regla de negocio: se muestra su motivo y no se reporta', function () {
    Exceptions::fake();
    Cal::$hookResult = fn () => throw ValidationException::withMessages(['start' => 'Solo en días laborables']);

    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false)
        ->assertReturned(['ok' => false, 'message' => 'Solo en días laborables']);

    Exceptions::assertNothingReported();
});

it('una AuthorizationException del hook (authorize()) se trata como «no editable»', function () {
    Exceptions::fake();
    Cal::$hookResult = fn () => throw new AuthorizationException('This action is unauthorized.');

    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false)
        ->assertReturned(['ok' => false, 'message' => 'Este evento no se puede editar']);

    Exceptions::assertNothingReported();
});

it('sin implementar el hook, el calendario editable rechaza y explica qué falta', function () {
    $t = Livewire::test(UnimplementedCalendar::class)->call('moveEvent', 1, ixTs('2026-10-15 14:00'), false);

    $t->assertReturned(fn (array $r) => $r['ok'] === false
        && str_contains($r['message'], 'persistMove()')
        && str_contains($r['message'], UnimplementedCalendar::class));

    $t = Livewire::test(UnimplementedCalendar::class)->call('resizeEvent', 1, ixTs('2026-10-14 12:00'));

    $t->assertReturned(fn (array $r) => $r['ok'] === false && str_contains($r['message'], 'persistResize()'));
});

// ── resizeEvent ────────────────────────────────────────────────────────

it('redimensionar persiste, dispara EventResized y anuncia el nuevo fin', function () {
    Event::fake([EventResized::class, EventMoved::class]);

    $t = Livewire::test(Cal::class)->call('resizeEvent', 1, ixTs('2026-10-14 13:00'));

    $t->assertReturned(['ok' => true, 'message' => '«Revisión» ahora termina 14 de octubre de 2026 13:00'])
        ->assertNotDispatched('kore:toast');

    [$event, $mutation] = Cal::$resized[0];

    expect($event->id)->toBe(1)
        ->and(ixIso($mutation->start))->toBe('2026-10-14T10:00:00+02:00')
        ->and(ixIso($mutation->end))->toBe('2026-10-14T13:00:00+02:00')
        ->and(Cal::$resized[0][2])->toBe(1)
        ->and(Cal::$moved)->toBe([]);

    Event::assertDispatched(EventResized::class, fn (EventResized $e) => $e->calendarClass === Cal::class && $e->event->id === 1 && $e->mutation === $mutation);
    Event::assertNotDispatched(EventMoved::class);
});

it('redimensionar desde el inicio lo anuncia como «ahora empieza»', function () {
    Livewire::test(Cal::class)->call('resizeEvent', 1, ixTs('2026-10-14 09:00'), true)
        ->assertReturned(['ok' => true, 'message' => '«Revisión» ahora empieza 14 de octubre de 2026 9:00']);

    expect(ixIso(Cal::$resized[0][1]->start))->toBe('2026-10-14T09:00:00+02:00')
        ->and(ixIso(Cal::$resized[0][1]->end))->toBe('2026-10-14T11:30:00+02:00');
});

it('un día completo anuncia el último día que cubre, no el fin exclusivo', function () {
    Cal::$events = [Cal::defaultEvents()[1]];

    Livewire::test(Cal::class)->call('resizeEvent', 2, ixTs('2026-10-19'))
        ->assertReturned(['ok' => true, 'message' => '«Congreso» ahora termina 18 de octubre de 2026']);

    expect(Cal::$resized[0][1]->endDate())->toBe('2026-10-19');
});

it('una duración cero o negativa se rechaza antes de llegar al hook', function () {
    foreach ([['2026-10-14 10:00', false], ['2026-10-14 08:00', false], ['2026-10-14 11:30', true], ['2026-10-14 12:00', true]] as [$edge, $fromStart]) {
        $t = Livewire::test(Cal::class)->call('resizeEvent', 1, ixTs($edge), $fromStart);

        $t->assertReturned(['ok' => false, 'message' => 'La duración no puede ser cero ni negativa']);
        expect(ixToast($t)['title'])->toBe('No se pudo cambiar la duración del evento');
    }

    expect(Cal::$resized)->toBe([]);
});

it('redimensionar respeta durationEditable, no startEditable', function () {
    Cal::$events = [
        ['id' => 1, 'title' => 'Fijo de duración', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00', 'durationEditable' => false],
        ['id' => 2, 'title' => 'Fijo de hora', 'start' => '2026-10-14 14:00', 'end' => '2026-10-14 15:00', 'startEditable' => false],
    ];

    Livewire::test(Cal::class)->call('resizeEvent', 1, ixTs('2026-10-14 12:00'))
        ->assertReturned(['ok' => false, 'message' => 'Este evento no se puede editar']);
    Livewire::test(Cal::class)->call('resizeEvent', 2, ixTs('2026-10-14 16:00'))
        ->assertReturned(fn (array $r) => $r['ok'] === true);

    // Y al revés al mover.
    Livewire::test(Cal::class)->call('moveEvent', 1, ixTs('2026-10-15 10:00'), false)
        ->assertReturned(fn (array $r) => $r['ok'] === true);
    Livewire::test(Cal::class)->call('moveEvent', 2, ixTs('2026-10-15 14:00'), false)
        ->assertReturned(['ok' => false, 'message' => 'Este evento no se puede editar']);
});

it('redimensionar: id inexistente, solape y rango válido se rechazan igual que al mover', function () {
    Livewire::test(Cal::class)->call('resizeEvent', 999, ixTs('2026-10-14 12:00'))
        ->assertReturned(['ok' => false, 'message' => 'El evento ya no está disponible']);

    Cal::$config = ['interaction.event_overlap' => false];
    Cal::$events = [
        ['id' => 1, 'title' => 'Uno', 'start' => '2026-10-14 10:00', 'end' => '2026-10-14 11:00'],
        ['id' => 2, 'title' => 'Dos', 'start' => '2026-10-14 12:00', 'end' => '2026-10-14 13:00'],
    ];
    Livewire::test(Cal::class)->call('resizeEvent', 1, ixTs('2026-10-14 12:30'))
        ->assertReturned(['ok' => false, 'message' => 'Se solapa con «Dos»']);

    Cal::$config = ['interaction.valid_range' => ['start' => '2026-10-14', 'end' => '2026-10-14']];
    Livewire::test(Cal::class)->call('resizeEvent', 1, ixTs('2026-10-15 01:00'))
        ->assertReturned(['ok' => false, 'message' => 'Fuera del rango permitido']);

    expect(Cal::$resized)->toBe([]);
});

it('soltar el borde donde estaba es un no-op', function () {
    Livewire::test(Cal::class)->call('resizeEvent', 1, ixTs('2026-10-14 11:30'))
        ->assertReturned(['ok' => true, 'message' => '']);

    expect(Cal::$resized)->toBe([]);
});

// ── selectRange ────────────────────────────────────────────────────────

it('selectRange ajusta, ordena y llama a onDateSelect con una Selection', function () {
    // Arrastrado hacia arriba (los bordes al revés) y fuera de la rejilla de 15 min.
    Livewire::test(Cal::class)->call('selectRange', ixTs('2026-10-14 12:07:00'), ixTs('2026-10-14 10:52:00'), false);

    expect(Cal::$selected)->toHaveCount(1);
    $s = Cal::$selected[0];

    expect($s->allDay)->toBeFalse()
        ->and(ixIso($s->start))->toBe('2026-10-14T10:45:00+02:00')
        ->and(ixIso($s->end))->toBe('2026-10-14T12:00:00+02:00')
        ->and($s->startMarker->toDateTimeString())->toBe('2026-10-14 10:45:00')
        ->and($s->resourceId)->toBeNull();
});

it('selectRange de día completo y con recurso', function () {
    ixRooms();

    Livewire::test(Cal::class)->call('selectRange', ixTs('2026-10-14'), ixTs('2026-10-16'), true, '7');

    $s = Cal::$selected[0];

    expect($s->allDay)->toBeTrue()
        ->and(ixIso($s->start))->toBe('2026-10-14T00:00:00+02:00')
        ->and(ixIso($s->end))->toBe('2026-10-16T00:00:00+02:00')
        ->and($s->resourceId)->toBe(7);
});

it('selectRange no hace nada si el calendario no es selectable', function () {
    Cal::$config = ['interaction.selectable' => false];

    $t = Livewire::test(Cal::class)->call('selectRange', ixTs('2026-10-14 10:00'), ixTs('2026-10-14 11:00'), false);

    expect(Cal::$selected)->toBe([]);
    $t->assertNotDispatched('kore:toast');
});

it('selectRange fuera del rango válido, con un marker absurdo o con un recurso que no existe: toast y sin hook', function () {
    Cal::$config = ['interaction.valid_range' => ['start' => '2026-10-13', 'end' => '2026-10-16']];

    $t = Livewire::test(Cal::class)->call('selectRange', ixTs('2026-10-20 10:00'), ixTs('2026-10-20 11:00'), false);
    expect(ixToast($t))->toMatchArray(['type' => 'error', 'title' => 'Fuera del rango permitido']);

    $t = Livewire::test(Cal::class)->call('selectRange', ixTs('2026-10-12 23:00'), ixTs('2026-10-13 01:00'), false);
    expect(ixToast($t)['title'])->toBe('Fuera del rango permitido');

    $t = Livewire::test(Cal::class)->call('selectRange', 0, PHP_INT_MAX, false);
    expect(ixToast($t)['title'])->toBe('Fuera del rango permitido');

    $t = Livewire::test(Cal::class)->call('selectRange', ixTs('2026-10-14 10:00'), ixTs('2026-10-14 11:00'), false, 'fantasma');
    expect(ixToast($t)['title'])->toBe('El recurso de destino no existe');

    expect(Cal::$selected)->toBe([]);
});

it('selectRange dentro del rango válido sí llega al hook', function () {
    Cal::$config = ['interaction.valid_range' => ['start' => '2026-10-13', 'end' => '2026-10-16']];

    Livewire::test(Cal::class)->call('selectRange', ixTs('2026-10-14 10:00'), ixTs('2026-10-14 11:00'), false);

    expect(Cal::$selected)->toHaveCount(1);
});

// ── Configuración fluida ───────────────────────────────────────────────

it('los métodos de configure() llegan a CalendarSettings', function () {
    $calendario = new class extends Cal
    {
        public function configure(): void
        {
            $this->timezone('Europe/Madrid')
                ->editable(false)
                ->selectable()
                ->snap('10m')
                ->eventOverlap(false)
                ->validRange('2026-01-01', '2026-06-30')
                ->allDayMaintainDuration()
                ->resourceEditable(false);
        }

        public function settings(): KoreUi\Calendar\View\CalendarSettings
        {
            return $this->koreSettingsResolved();
        }
    };

    $calendario->booted();
    $s = $calendario->settings();

    expect($s->editable)->toBeFalse()
        ->and($s->selectable)->toBeTrue()
        ->and($s->snapSeconds)->toBe(600)
        ->and($s->eventOverlap)->toBeFalse()
        ->and($s->allDayMaintainDuration)->toBeTrue()
        ->and($s->resourceEditable)->toBeFalse()
        ->and((string) $s->validRange->start)->toBe('2026-01-01 00:00:00')
        ->and((string) $s->validRange->end)->toBe('2026-07-01 00:00:00');
});

it('los métodos de interacción no son acciones del navegador', function () {
    // Son protegidos: Livewire solo expone lo público.
    foreach (['editable', 'selectable', 'snap', 'eventOverlap', 'validRange', 'allDayMaintainDuration', 'resourceEditable', 'persistMove', 'persistResize', 'onDateSelect', 'korePersist', 'koreReject', 'koreInstances'] as $metodo) {
        expect((new ReflectionMethod(Cal::class, $metodo))->isPublic())->toBeFalse($metodo);
    }

    foreach (['moveEvent', 'resizeEvent', 'selectRange'] as $accion) {
        expect((new ReflectionMethod(Cal::class, $accion))->isPublic())->toBeTrue($accion);
    }
});

it('el calendario sigue pintándose con la interacción activa', function () {
    Livewire::test(Cal::class)->assertSee('Revisión')->assertSee('Congreso');
});
