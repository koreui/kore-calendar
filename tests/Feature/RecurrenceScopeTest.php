<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\Sources\ArraySource;
use KoreUi\Calendar\Interaction\Events\EventMoved;
use KoreUi\Calendar\Interaction\Events\EventResized;
use KoreUi\Calendar\Overlays\EventForm;
use KoreUi\Calendar\Overlays\RecurrenceScope;
use KoreUi\Calendar\Recurrence\RecurrenceEditor;
use KoreUi\Calendar\Tests\Feature\Fixtures\RecurringCalendar as Cal;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| Editar una serie recurrente por alcance. Mover, redimensionar, editar o eliminar una
| ocurrencia NO guarda nada hasta que el usuario elige el alcance: la acción queda
| pendiente (`koreRecurrencePending`, #[Locked]), se abre el diálogo, y
| `applyRecurrenceScope()` la repite entera, volviéndola a validar.
|
| La semana de los tests es la del lunes 12 al domingo 18 de octubre de 2026, en
| Europe/Madrid (RecurringCalendar):
|   s  «Standup»  cada día 09:00-09:30, 14 veces (12 al 25 de octubre)
|   p  «Comida»   mié 14, 14:00-15:00 (un evento normal)
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
    Cal::fresh();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function rsTs(string $wall): int
{
    return Marker::parse($wall)->ts;
}

/** El instanceId de la ocurrencia de la serie que empieza (original) a esa hora de pared. */
function rsId(string $wall, string $series = 's'): string
{
    return $series.':'.rsTs($wall);
}

/** Los params del primer evento de Livewire con ese nombre, o null. */
function rsDispatched($testable, string $name): ?array
{
    return collect(data_get($testable->effects, 'dispatches'))->firstWhere('name', $name)['params'] ?? null;
}

/** Los toasts de un tipo que salieron en la última petición. */
function rsToasts($testable, string $type): array
{
    return collect(data_get($testable->effects, 'dispatches'))
        ->where('name', 'kore:toast')
        ->map(fn ($d) => $d['params']['toast'])
        ->filter(fn ($t) => ($t['type'] ?? null) === $type)
        ->values()
        ->all();
}

/** Lo que dibuja la semana con lo guardado ahora: «dd hh:mm-hh:mm título», ordenado. */
function rsWeek(): array
{
    $clock = new WallClock('Europe/Madrid');
    $instances = (new EventCollector($clock, new EventNormalizer($clock)))
        ->collect([new ArraySource(Cal::$rows ?? Cal::defaultRows())], new DateRange(Marker::parse('2026-10-12'), Marker::parse('2026-10-19')));
    usort($instances, fn ($a, $b) => [$a->range->start->ts, $a->event->title] <=> [$b->range->start->ts, $b->event->title]);

    return array_map(fn ($i) => gmdate('d H:i', $i->range->start->ts).'-'.gmdate('H:i', $i->range->end->ts).' '.$i->event->title, $instances);
}

/** El formulario de una ocurrencia devuelve lo que el usuario escribió. */
function rsSave($testable, array $data)
{
    return $testable->dispatch(EventForm::SAVED, calendar: $testable->id(), data: $data);
}

/** Datos de un formulario de edición válido, de la ocurrencia del miércoles 14 a las 09:00. */
function rsEdit(array $over = []): array
{
    return [
        'mode' => 'edit', 'id' => 's', 'instanceId' => rsId('2026-10-14 09:00'),
        'title' => 'Standup largo', 'allDay' => false,
        'start' => '2026-10-14 09:00:00', 'end' => '2026-10-14 10:00:00', 'resourceId' => null, 'color' => null,
        ...$over,
    ];
}

const RS_WEDNESDAY = '2026-10-14 09:00';

// ── Sin alcance no se guarda: se pregunta ──────────────────────────────

it('mover una ocurrencia sin alcance no persiste: devuelve needsScope y abre el diálogo', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false);

    $t->assertReturned(['ok' => false, 'needsScope' => true, 'message' => ''])
        ->assertDispatched('kore:open')
        // El cliente no tiene nada que anunciar ni se muestra un error.
        ->assertNotDispatched('kore:toast');

    expect(Cal::$moved)->toBe([]);

    $open = rsDispatched($t, 'kore:open');

    expect($open['name'])->toBe('kore-calendar-recurrence-scope')
        ->and($open['arguments'])->toBe(['calendar' => $t->id(), 'action' => 'move', 'title' => 'Standup', 'locale' => 'es'])
        ->and($open['overlayAttributes'])->toBe(['title' => 'Mover un evento recurrente']);

    // Lo pendiente: la acción y lo que el navegador mandó, no una mutación.
    expect($t->get('koreRecurrencePending'))->toBe([
        'action' => 'move',
        'instanceId' => rsId(RS_WEDNESDAY),
        'arguments' => ['start' => rsTs('2026-10-15 11:00'), 'allDay' => false, 'resourceId' => null, 'fromResourceId' => null],
    ]);
});

it('redimensionar una ocurrencia sin alcance tampoco persiste', function () {
    $t = Livewire::test(Cal::class)->call('resizeEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-14 10:00'));

    $t->assertReturned(['ok' => false, 'needsScope' => true, 'message' => '']);

    expect(Cal::$resized)->toBe([])
        ->and(rsDispatched($t, 'kore:open')['arguments']['action'])->toBe('resize')
        ->and(rsDispatched($t, 'kore:open')['overlayAttributes']['title'])->toBe('Cambiar la duración de un evento recurrente');
});

it('no se pregunta por un cambio que de todas formas se rechazaría, ni por soltarlo donde estaba', function () {
    // Una serie marcada `editable: false`: el rechazo es el de siempre.
    Cal::$rows = [['id' => 's', 'title' => 'Festivos', 'start' => '2026-10-12', 'allDay' => true, 'rrule' => 'FREQ=DAILY', 'editable' => false]];

    $t = Livewire::test(Cal::class)->call('moveEvent', rsId('2026-10-14', 's'), rsTs('2026-10-15'), true);

    $t->assertReturned(['ok' => false, 'message' => 'Este evento no se puede editar'])->assertNotDispatched('kore:open');
    expect($t->get('koreRecurrencePending'))->toBeNull();

    // Soltar la ocurrencia donde estaba no es un cambio: no hay nada que preguntar.
    Cal::$rows = null;
    $same = Livewire::test(Cal::class)->call('moveEvent', rsId(RS_WEDNESDAY), rsTs(RS_WEDNESDAY), false);

    $same->assertReturned(['ok' => true, 'message' => ''])->assertNotDispatched('kore:open');
    expect($same->get('koreRecurrencePending'))->toBeNull();
});

it('un evento normal no pide alcance, con su id o con su instanceId', function () {
    $porId = Livewire::test(Cal::class)->call('moveEvent', 'p', rsTs('2026-10-14 16:00'), false);

    $porId->assertReturned(['ok' => true, 'message' => '«Comida» movido al 14 de octubre de 2026 16:00'])->assertNotDispatched('kore:open');

    Cal::fresh();
    $porInstancia = Livewire::test(Cal::class)->call('moveEvent', rsId('2026-10-14 14:00', 'p'), rsTs('2026-10-14 16:00'), false);

    $porInstancia->assertReturned(['ok' => true, 'message' => '«Comida» movido al 14 de octubre de 2026 16:00']);

    expect(Cal::$moved)->toHaveCount(1)
        ->and(Cal::$moved[0][1]->scope)->toBeNull()
        ->and(Cal::$moved[0][1]->seriesId)->toBeNull()
        ->and(Cal::$moved[0][1]->isOccurrence())->toBeFalse();
});

// ── Elegir el alcance ──────────────────────────────────────────────────

it('this: persistMove recibe el alcance y qué ocurrencia es', function () {
    Event::fake([EventMoved::class]);

    $t = Livewire::test(Cal::class)
        ->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false)
        ->call('applyRecurrenceScope', 'this');

    expect(Cal::$moved)->toHaveCount(1);
    [$event, $m] = Cal::$moved[0];

    // El evento es la ocurrencia (con SUS fechas, sin regla); la serie, por seriesId.
    expect($event->id)->toBe('s')
        ->and($event->rrule)->toBeNull()
        ->and($m->scope)->toBe('this')
        ->and($m->seriesId)->toBe('s')
        ->and($m->isOccurrence())->toBeTrue()
        // Qué ocurrencia es: la ORIGINAL (miércoles 09:00), no donde se soltó.
        ->and($m->recurrenceId->ts)->toBe(rsTs(RS_WEDNESDAY))
        ->and($m->occurrenceStart->format('c'))->toBe('2026-10-14T09:00:00+02:00')
        // Y dónde queda.
        ->and($m->start->format('c'))->toBe('2026-10-15T11:00:00+02:00')
        ->and($m->end->format('c'))->toBe('2026-10-15T11:30:00+02:00');

    // Lo pendiente se consume, y como el gesto ya terminó, el resultado sale en un toast.
    expect($t->get('koreRecurrencePending'))->toBeNull()
        ->and(rsToasts($t, 'success')[0]['title'])->toBe('«Standup» movido al 15 de octubre de 2026 11:00');

    Event::assertDispatched(EventMoved::class, fn (EventMoved $e) => $e->mutation->scope === 'this' && $e->event->id === 's');
});

it('following: persistMove recibe el alcance y la ocurrencia desde la que corta', function () {
    Livewire::test(Cal::class)
        ->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-14 11:00'), false)
        ->call('applyRecurrenceScope', 'following');

    $m = Cal::$moved[0][1];

    expect($m->scope)->toBe('following')
        ->and($m->recurrenceId->ts)->toBe(rsTs(RS_WEDNESDAY))
        ->and($m->start->format('c'))->toBe('2026-10-14T11:00:00+02:00');
});

it('all: la mutación es el desplazamiento de la serie, no la posición de la ocurrencia', function () {
    $t = Livewire::test(Cal::class)
        ->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false)
        ->call('applyRecurrenceScope', 'all');

    $m = Cal::$moved[0][1];

    // Un día y dos horas: lo que se movió ESTA ocurrencia, y por tanto lo que hay que mover la serie.
    expect($m->scope)->toBe('all')
        ->and($m->deltaSeconds)->toBe(86_400 + 2 * 3600)
        // `start` sigue siendo dónde queda la ocurrencia: guardarlo como inicio de la serie la movería a esa fecha.
        ->and($m->start->format('c'))->toBe('2026-10-15T11:00:00+02:00');

    $series = CalendarEvent::fromArray(Cal::defaultRows()[0]);

    expect(RecurrenceEditor::shift($series, $m->deltaSeconds))->toBe(['start' => '2026-10-13 11:00:00', 'end' => '2026-10-13 11:30:00']);
});

it('redimensionar con alcance llama a persistResize con él', function () {
    Event::fake([EventResized::class]);

    Livewire::test(Cal::class)
        ->call('resizeEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-14 10:00'))
        ->call('applyRecurrenceScope', 'all');

    expect(Cal::$resized)->toHaveCount(1)
        ->and(Cal::$moved)->toBe([]);

    $m = Cal::$resized[0][1];

    expect($m->scope)->toBe('all')
        ->and($m->seriesId)->toBe('s')
        ->and($m->deltaSeconds)->toBe(1800)
        ->and($m->end->format('c'))->toBe('2026-10-14T10:00:00+02:00');

    Event::assertDispatched(EventResized::class, fn (EventResized $e) => $e->mutation->scope === 'all');
});

// ── Lo que no se acepta ────────────────────────────────────────────────

it('un alcance que no existe, o ninguna acción pendiente, no hacen nada', function () {
    // Sin pendiente.
    $sin = Livewire::test(Cal::class)->call('applyRecurrenceScope', 'all');

    expect(Cal::$moved)->toBe([]);
    $sin->assertNotDispatched('kore:toast');

    // Con pendiente pero un alcance inventado: no se persiste, y la pendiente sigue ahí
    // para el alcance bueno (el navegador no puede hacer que se pierda).
    $t = Livewire::test(Cal::class)->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false);

    foreach (['', 'todas', 'ALL', 'this;drop'] as $malo) {
        $t->call('applyRecurrenceScope', $malo);
    }

    expect(Cal::$moved)->toBe([])
        ->and($t->get('koreRecurrencePending'))->not->toBeNull();

    $t->call('applyRecurrenceScope', 'this');

    expect(Cal::$moved)->toHaveCount(1);
});

it('el alcance elegido se aplica una sola vez: la pendiente se consume antes de intentarlo', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false);

    $t->call('applyRecurrenceScope', 'this')->call('applyRecurrenceScope', 'all');

    expect(Cal::$moved)->toHaveCount(1);

    // Y si el hook rechaza, tampoco se puede reintentar con otro alcance.
    Cal::fresh();
    Cal::$hookResult = 'No hay sitio';
    $r = Livewire::test(Cal::class)->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false)->call('applyRecurrenceScope', 'this');

    expect($r->get('koreRecurrencePending'))->toBeNull()
        ->and(rsToasts($r, 'error')[0]['description'])->toBe('No hay sitio');

    $r->call('applyRecurrenceScope', 'all');
    expect(Cal::$moved)->toHaveCount(1);
});

it('el navegador no puede escribir la acción pendiente', function () {
    Livewire::test(Cal::class)->set('koreRecurrencePending', ['action' => 'move', 'instanceId' => 's:1', 'arguments' => []]);
})->throws(CannotUpdateLockedPropertyException::class);

it('si la ocurrencia ya no existe al elegir el alcance, se rechaza', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false);

    // Mientras el diálogo estaba abierto, otra pestaña dejó la serie en dos ocurrencias.
    Cal::$rows = [['id' => 's', 'title' => 'Standup', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 09:30', 'rrule' => 'FREQ=DAILY;COUNT=2']];

    $t->call('applyRecurrenceScope', 'this');

    expect(Cal::$moved)->toBe([])
        ->and($t->get('koreRecurrencePending'))->toBeNull()
        ->and(rsToasts($t, 'error')[0]['title'])->toBe('No se pudo mover el evento')
        ->and(rsToasts($t, 'error')[0]['description'])->toBe('El evento ya no está disponible');
});

it('lo que valía al pedir el alcance se vuelve a validar al elegirlo', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false);

    // Ahora esa serie no se puede editar.
    Cal::$rows = [['id' => 's', 'title' => 'Standup', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 09:30', 'rrule' => 'FREQ=DAILY;COUNT=14', 'editable' => false]];

    $t->call('applyRecurrenceScope', 'this');

    expect(Cal::$moved)->toBe([])
        ->and(rsToasts($t, 'error')[0]['description'])->toBe('Este evento no se puede editar');
});

it('un instanceId ajeno se rechaza, y el id de la serie no basta para identificar una ocurrencia', function () {
    foreach ([
        'una ocurrencia que no existe' => rsId('2026-10-14 10:00'),
        'de otra serie' => rsId(RS_WEDNESDAY, 'zz'),
        'basura' => 's:no-es-un-numero',
        'el id de la serie' => 's',
    ] as $caso => $id) {
        $t = Livewire::test(Cal::class)->call('moveEvent', $id, rsTs('2026-10-15 11:00'), false);

        $t->assertReturned(['ok' => false, 'message' => 'El evento ya no está disponible'])->assertNotDispatched('kore:open');
        expect($t->get('koreRecurrencePending'))->toBeNull($caso);
    }

    foreach (['s', rsId('2026-10-14 10:00')] as $id) {
        Livewire::test(Cal::class)->call('resizeEvent', $id, rsTs('2026-10-14 10:00'))->assertReturned(['ok' => false, 'message' => 'El evento ya no está disponible']);
    }

    expect(Cal::$moved)->toBe([])->and(Cal::$resized)->toBe([]);
});

it('con deshacer activado, el cambio de una ocurrencia no se ofrece deshacer', function () {
    Cal::$undoable = true;

    $t = Livewire::test(Cal::class)
        ->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false)
        ->call('applyRecurrenceScope', 'this');

    expect($t->get('koreLastChange'))->toBeNull();

    // Un evento normal sí.
    $plano = Livewire::test(Cal::class)->call('moveEvent', 'p', rsTs('2026-10-14 16:00'), false);

    expect($plano->get('koreLastChange'))->not->toBeNull();
});

// ── Los avisos del diálogo ─────────────────────────────────────────────

it('la elección llega con un evento de Livewire y solo la atiende el calendario destino', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false);

    // Otro calendario de la página: se ignora.
    $t->dispatch(RecurrenceScope::CHOSEN, calendar: 'otro-calendario', scope: 'this');
    expect(Cal::$moved)->toBe([])->and($t->get('koreRecurrencePending'))->not->toBeNull();

    $t->dispatch(RecurrenceScope::CHOSEN, calendar: $t->id(), scope: 'this');
    expect(Cal::$moved)->toHaveCount(1)->and(Cal::$moved[0][1]->scope)->toBe('this');
});

it('cancelar descarta la acción pendiente', function () {
    $t = Livewire::test(Cal::class)->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false);

    $t->dispatch(RecurrenceScope::CANCELLED, calendar: 'otro-calendario');
    expect($t->get('koreRecurrencePending'))->not->toBeNull();

    $t->dispatch(RecurrenceScope::CANCELLED, calendar: $t->id());
    expect($t->get('koreRecurrencePending'))->toBeNull();

    // Sin pendiente, elegir ya no hace nada.
    $t->call('applyRecurrenceScope', 'all');
    expect(Cal::$moved)->toBe([]);

    // Y la acción de cancelar, llamada directamente.
    $t->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-15 11:00'), false)->call('cancelRecurrenceScope');
    expect($t->get('koreRecurrencePending'))->toBeNull();
});

// ── El formulario ──────────────────────────────────────────────────────

it('pulsar una ocurrencia abre el formulario sabiendo cuál es y que no debe cerrarse solo', function () {
    $t = Livewire::test(Cal::class)->call('clickEvent', rsId(RS_WEDNESDAY));

    $open = rsDispatched($t, 'kore:open');

    expect($open['name'])->toBe('kore-calendar-event-form')
        ->and($open['arguments'])->toMatchArray([
            'mode' => 'edit',
            'eventId' => 's',
            'instanceId' => rsId(RS_WEDNESDAY),
            'recurring' => true,
            'start' => '2026-10-14 09:00:00',
            'end' => '2026-10-14 09:30:00',
        ]);

    // Un evento normal no lleva ni lo uno ni lo otro.
    $normal = rsDispatched(Livewire::test(Cal::class)->call('clickEvent', 'p'), 'kore:open');

    expect($normal['arguments'])->not->toHaveKeys(['instanceId', 'recurring']);
});

it('pulsar con el id de la serie no abre nada: no se sabe cuál es', function () {
    $t = Livewire::test(Cal::class)->call('clickEvent', 's');

    $t->assertNotDispatched('kore:open');
    expect(rsToasts($t, 'error')[0]['title'])->toBe('El evento ya no está disponible');
});

it('guardar una ocurrencia desde el formulario pide alcance y no llama a updateEvent', function () {
    $t = rsSave(Livewire::test(Cal::class), rsEdit());

    $t->assertDispatched('kore:open')->assertNotDispatched('kore:toast');

    expect(Cal::$updated)->toBe([])
        ->and(rsDispatched($t, 'kore:open')['arguments'])->toBe(['calendar' => $t->id(), 'action' => 'edit', 'title' => 'Standup', 'locale' => 'es'])
        ->and(rsDispatched($t, 'kore:open')['overlayAttributes']['title'])->toBe('Editar un evento recurrente');

    // Lo pendiente son los datos ya validados, sin instantes (Livewire no lleva un Carbon).
    $pending = $t->get('koreRecurrencePending');

    expect($pending['action'])->toBe('edit')
        ->and($pending['instanceId'])->toBe(rsId(RS_WEDNESDAY))
        ->and($pending['arguments']['data'])->toMatchArray(['title' => 'Standup largo', 'start' => '2026-10-14 09:00:00'])
        ->and($pending['arguments']['data'])->not->toHaveKeys(['startAt', 'endAt']);
});

it('al elegir el alcance, updateEvent recibe scope, seriesId, recurrenceId y occurrenceStart', function () {
    $t = rsSave(Livewire::test(Cal::class), rsEdit(['start' => '2026-10-14 10:00:00', 'end' => '2026-10-14 10:30:00']))
        ->call('applyRecurrenceScope', 'all');

    expect(Cal::$updated)->toHaveCount(1);
    [$event, $data] = Cal::$updated[0];

    expect($event->id)->toBe('s')
        ->and($data)->toMatchArray([
            'mode' => 'edit',
            'id' => 's',
            'title' => 'Standup largo',
            'scope' => 'all',
            'seriesId' => 's',
            // Una hora de desplazamiento: lo que `all` suma al inicio de la serie.
            'deltaSeconds' => 3600,
        ])
        ->and($data['recurrenceId'])->toBeInstanceOf(Marker::class)
        ->and($data['recurrenceId']->ts)->toBe(rsTs(RS_WEDNESDAY))
        ->and($data['occurrenceStart']->format('c'))->toBe('2026-10-14T09:00:00+02:00')
        // Los instantes de siempre, ya calculados.
        ->and($data['startAt']->format('c'))->toBe('2026-10-14T10:00:00+02:00');

    expect(rsToasts($t, 'success')[0]['title'])->toBe('«Standup largo» guardado')
        ->and($t->get('koreRecurrencePending'))->toBeNull();
});

it('eliminar una ocurrencia desde el formulario pide alcance', function () {
    $t = rsSave(Livewire::test(Cal::class), ['mode' => 'delete', 'id' => 's', 'instanceId' => rsId(RS_WEDNESDAY)]);

    $t->assertDispatched('kore:open');

    expect(Cal::$deleted)->toBe([])
        ->and(rsDispatched($t, 'kore:open')['arguments']['action'])->toBe('delete')
        ->and(rsDispatched($t, 'kore:open')['overlayAttributes']['title'])->toBe('Eliminar un evento recurrente');

    $t->call('applyRecurrenceScope', 'following');

    expect(Cal::$deleted)->toHaveCount(1);
    [$event, $data] = Cal::$deleted[0];

    expect($event->id)->toBe('s')
        ->and($data)->toMatchArray(['mode' => 'delete', 'id' => 's', 'scope' => 'following', 'seriesId' => 's'])
        ->and($data['recurrenceId']->ts)->toBe(rsTs(RS_WEDNESDAY))
        ->and($data['occurrenceStart']->format('c'))->toBe('2026-10-14T09:00:00+02:00');

    expect(rsToasts($t, 'success')[0]['title'])->toBe('«Standup» eliminado');
});

it('desde el formulario, un id de serie sin instanceId o uno ajeno se rechazan', function () {
    foreach ([['instanceId' => null], ['instanceId' => rsId('2026-10-14 10:00')], ['instanceId' => ['s']]] as $over) {
        $edit = rsSave(Livewire::test(Cal::class), rsEdit($over));
        $delete = rsSave(Livewire::test(Cal::class), ['mode' => 'delete', 'id' => 's', ...$over]);

        foreach ([$edit, $delete] as $t) {
            $t->assertNotDispatched('kore:open');
            expect(rsToasts($t, 'error')[0]['description'])->toBe('El evento ya no está disponible');
        }
    }

    expect(Cal::$updated)->toBe([])->and(Cal::$deleted)->toBe([]);
});

it('un cambio del formulario no válido no pide alcance', function () {
    $t = rsSave(Livewire::test(Cal::class), rsEdit(['title' => '   ']));

    $t->assertNotDispatched('kore:open');
    expect(rsToasts($t, 'error')[0]['description'])->toBe('El cambio no es válido')
        ->and($t->get('koreRecurrencePending'))->toBeNull();
});

it('un evento normal editado desde el formulario sigue su camino de siempre, sin alcance', function () {
    $t = rsSave(Livewire::test(Cal::class), rsEdit(['id' => 'p', 'instanceId' => null, 'title' => 'Comida larga', 'start' => '2026-10-14 14:00:00', 'end' => '2026-10-14 15:30:00']));

    $t->assertNotDispatched('kore:open');

    expect(Cal::$updated)->toHaveCount(1)
        ->and(Cal::$updated[0][1])->not->toHaveKeys(['scope', 'seriesId', 'recurrenceId', 'occurrenceStart', 'deltaSeconds']);

    // Borrar uno normal: el hook de siempre, con `$data` de segundo argumento.
    rsSave(Livewire::test(Cal::class), ['mode' => 'delete', 'id' => 'p']);

    expect(Cal::$deleted)->toHaveCount(1)
        ->and(Cal::$deleted[0][1])->toBe(['mode' => 'delete', 'id' => 'p']);
});

it('si el aviso era de un formulario de serie que ya no lo es, se guarda y se cierra la pila', function () {
    // El formulario creyó que era una ocurrencia (trae instanceId) pero el evento es normal.
    $t = rsSave(Livewire::test(Cal::class), rsEdit(['id' => 'p', 'instanceId' => rsId('2026-10-14 14:00', 'p'), 'start' => '2026-10-14 14:00:00', 'end' => '2026-10-14 15:30:00']));

    $t->assertNotDispatched('kore:open')->assertDispatched('kore:close');

    expect(Cal::$updated)->toHaveCount(1);
});

// ── De principio a fin: lo guardado, expandido de nuevo ────────────────

/** Mueve la ocurrencia del miércoles 14 a las 11:00 con el alcance dado. */
function rsMoveWednesday(string $scope)
{
    return Livewire::test(Cal::class)
        ->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-14 11:00'), false)
        ->call('applyRecurrenceScope', $scope);
}

$base = ['12 09:00-09:30 Standup', '13 09:00-09:30 Standup', '14 09:00-09:30 Standup', '14 14:00-15:00 Comida', '15 09:00-09:30 Standup', '16 09:00-09:30 Standup', '17 09:00-09:30 Standup', '18 09:00-09:30 Standup'];

it('la semana de partida es la que se espera', function () use ($base) {
    expect(rsWeek())->toBe($base);
});

it('flujo completo, this + mover: solo esa ocurrencia cambia', function () {
    Cal::$store = true;
    rsMoveWednesday('this');

    expect(rsWeek())->toBe(['12 09:00-09:30 Standup', '13 09:00-09:30 Standup', '14 11:00-11:30 Standup', '14 14:00-15:00 Comida', '15 09:00-09:30 Standup', '16 09:00-09:30 Standup', '17 09:00-09:30 Standup', '18 09:00-09:30 Standup']);

    // Una sola excepción, que apunta a la serie y a la ocurrencia por su inicio original.
    $override = collect(Cal::$rows)->firstWhere('recurrenceOf', 's');

    expect($override['recurrenceId'])->toBe('2026-10-14 09:00:00')
        ->and(collect(Cal::$rows)->firstWhere('id', 's')['exdates'] ?? [])->toBe([]);
});

it('flujo completo, this sobre una ocurrencia ya modificada: actualiza su excepción, no crea otra', function () {
    Cal::$store = true;
    rsMoveWednesday('this');

    // Se vuelve a mover la misma (ahora a las 11:00): el instanceId sigue siendo el de su inicio original.
    Livewire::test(Cal::class)
        ->call('moveEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-14 12:00'), false)
        ->call('applyRecurrenceScope', 'this');

    expect(collect(Cal::$rows)->where('recurrenceOf', 's'))->toHaveCount(1)
        ->and(rsWeek())->toContain('14 12:00-12:30 Standup')
        ->and(rsWeek())->not->toContain('14 11:00-11:30 Standup')
        ->and(rsWeek())->not->toContain('14 09:00-09:30 Standup');
});

it('flujo completo, following + mover: la serie se corta y nace otra desde ahí', function () {
    Cal::$store = true;
    rsMoveWednesday('following');

    expect(rsWeek())->toBe(['12 09:00-09:30 Standup', '13 09:00-09:30 Standup', '14 11:00-11:30 Standup', '14 14:00-15:00 Comida', '15 11:00-11:30 Standup', '16 11:00-11:30 Standup', '17 11:00-11:30 Standup', '18 11:00-11:30 Standup']);

    $rows = collect(Cal::$rows);

    // 14 ocurrencias: 2 antes del miércoles (la original se corta un segundo antes de las 09:00,
    // en hora de pared como UTC porque la regla es flotante) y 12 desde el miércoles.
    expect($rows->firstWhere('id', 's')['rrule'])->toBe('FREQ=DAILY;UNTIL=20261014T085959Z')
        ->and($rows->firstWhere('id', 'n1')['rrule'])->toBe('FREQ=DAILY;COUNT=12');
});

it('flujo completo, following sobre la primera ocurrencia es toda la serie', function () {
    Cal::$store = true;

    Livewire::test(Cal::class)
        ->call('moveEvent', rsId('2026-10-12 09:00'), rsTs('2026-10-12 11:00'), false)
        ->call('applyRecurrenceScope', 'following');

    // Sin serie nueva ni corte: la misma serie, desplazada.
    expect(collect(Cal::$rows)->pluck('id')->all())->toBe(['s', 'p'])
        ->and(rsWeek()[0])->toBe('12 11:00-11:30 Standup');
});

it('flujo completo, all + mover: toda la serie se desplaza lo mismo', function () {
    Cal::$store = true;
    rsMoveWednesday('all');

    expect(rsWeek())->toBe(['12 11:00-11:30 Standup', '13 11:00-11:30 Standup', '14 11:00-11:30 Standup', '14 14:00-15:00 Comida', '15 11:00-11:30 Standup', '16 11:00-11:30 Standup', '17 11:00-11:30 Standup', '18 11:00-11:30 Standup']);
});

it('flujo completo, all + redimensionar: todas pasan a durar lo mismo', function () {
    Cal::$store = true;

    Livewire::test(Cal::class)
        ->call('resizeEvent', rsId(RS_WEDNESDAY), rsTs('2026-10-14 10:00'))
        ->call('applyRecurrenceScope', 'all');

    expect(array_values(array_filter(rsWeek(), fn ($l) => str_contains($l, 'Standup'))))
        ->toBe(['12 09:00-10:00 Standup', '13 09:00-10:00 Standup', '14 09:00-10:00 Standup', '15 09:00-10:00 Standup', '16 09:00-10:00 Standup', '17 09:00-10:00 Standup', '18 09:00-10:00 Standup']);
});

it('flujo completo, this + editar desde el formulario', function () {
    Cal::$store = true;

    rsSave(Livewire::test(Cal::class), rsEdit(['title' => 'Standup largo', 'start' => '2026-10-14 09:00:00', 'end' => '2026-10-14 10:00:00']))
        ->call('applyRecurrenceScope', 'this');

    expect(rsWeek())->toContain('14 09:00-10:00 Standup largo')
        ->and(rsWeek())->not->toContain('14 09:00-09:30 Standup')
        ->and(collect(rsWeek())->filter(fn ($l) => str_ends_with($l, ' Standup')))->toHaveCount(6);
});

it('flujo completo, all + editar desde el formulario: el título cambia en toda la serie', function () {
    Cal::$store = true;

    rsSave(Livewire::test(Cal::class), rsEdit(['title' => 'Daily', 'start' => '2026-10-14 09:00:00', 'end' => '2026-10-14 09:30:00']))
        ->call('applyRecurrenceScope', 'all');

    expect(collect(rsWeek())->filter(fn ($l) => str_ends_with($l, ' Daily')))->toHaveCount(7);
});

it('flujo completo, eliminar con cada alcance', function () {
    Cal::$store = true;

    $delete = fn (string $scope) => rsSave(Livewire::test(Cal::class), ['mode' => 'delete', 'id' => 's', 'instanceId' => rsId(RS_WEDNESDAY)])->call('applyRecurrenceScope', $scope);

    // this: un EXDATE en la representación de la serie.
    $delete('this');
    expect(collect(Cal::$rows)->firstWhere('id', 's')['exdates'])->toBe(['2026-10-14 09:00:00'])
        ->and(collect(rsWeek())->filter(fn ($l) => str_ends_with($l, ' Standup')))->toHaveCount(6)
        ->and(rsWeek())->not->toContain('14 09:00-09:30 Standup');

    // following: la regla se corta antes del miércoles.
    Cal::fresh();
    Cal::$store = true;
    $delete('following');
    expect(collect(rsWeek())->filter(fn ($l) => str_ends_with($l, ' Standup'))->values()->all())->toBe(['12 09:00-09:30 Standup', '13 09:00-09:30 Standup']);

    // all: la serie entera.
    Cal::fresh();
    Cal::$store = true;
    $delete('all');
    expect(rsWeek())->toBe(['14 14:00-15:00 Comida']);
});
