<?php

use KoreUi\Calendar\Overlays\RecurrenceScope;
use KoreUi\Overlay\OverlayManager;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| El diálogo de alcance. No cambia nada: devuelve la elección al calendario con un
| evento de Livewire que lleva el id del calendario destino, y el calendario es quien
| repite la acción pendiente (tests/Feature/RecurrenceScopeTest.php).
*/

beforeEach(function () {
    app()->setLocale('es');
});

const RS_CAL = 'calendar-id-1';

function rsoArgs(array $over = []): array
{
    return ['calendar' => RS_CAL, 'action' => 'move', 'title' => 'Standup', ...$over];
}

function rsoDispatched($testable, string $name): ?array
{
    return collect(data_get($testable->effects, 'dispatches'))->firstWhere('name', $name)['params'] ?? null;
}

it('es un overlay de kore-ui: un modal que solo se descarta con Cancelar o Escape, y solo él', function () {
    expect(new RecurrenceScope)->toBeInstanceOf(KoreUi\Core\Contracts\Overlayable::class)
        ->and(RecurrenceScope::overlayType())->toBe('modal')
        ->and(RecurrenceScope::closesOnClickAway())->toBeFalse()
        ->and(RecurrenceScope::closesOnEscape())->toBeTrue()
        // Escape vuelve al formulario de debajo, si lo hay, en vez de cerrar la pila.
        ->and(RecurrenceScope::escapeClosesAll())->toBeFalse()
        ->and(RecurrenceScope::destroysOnClose())->toBeTrue();
});

it('el OverlayManager de kore-ui lo abre por su nombre', function () {
    $manager = Livewire::test(OverlayManager::class)
        ->dispatch('kore:open', name: 'kore-calendar-recurrence-scope', arguments: rsoArgs(), overlayAttributes: ['title' => 'Mover un evento recurrente'])
        ->assertDispatched('kore:overlay-changed');

    $abierto = array_values($manager->get('overlays'))[0];

    expect($abierto['name'])->toBe('kore-calendar-recurrence-scope')
        ->and($abierto['overlayAttributes']['title'])->toBe('Mover un evento recurrente')
        ->and($abierto['overlayAttributes']['closesOnClickAway'])->toBeFalse()
        ->and($abierto['overlayAttributes']['escapeClosesAll'])->toBeFalse();
});

it('pinta las tres opciones y Cancelar, con el título de la acción y el del evento', function () {
    Livewire::test(RecurrenceScope::class, rsoArgs())
        ->assertSee('Mover un evento recurrente')
        ->assertSee('«Standup» se repite')
        ->assertSee('Solo este evento')
        ->assertSee('Este y los siguientes')
        ->assertSee('Todos los eventos')
        ->assertSee('Cancelar')
        ->assertSeeHtml("wire:click=\"choose('this')\"")
        ->assertSeeHtml("wire:click=\"choose('following')\"")
        ->assertSeeHtml("wire:click=\"choose('all')\"");

    foreach (['move' => 'Mover un evento', 'resize' => 'Cambiar la duración', 'edit' => 'Editar un evento', 'delete' => 'Eliminar un evento'] as $accion => $titulo) {
        Livewire::test(RecurrenceScope::class, rsoArgs(['action' => $accion]))->assertSee($titulo);
    }

    // Una acción que no se conoce se trata como mover, no rompe.
    Livewire::test(RecurrenceScope::class, rsoArgs(['action' => 'otra']))->assertSet('action', 'move');
});

it('todos los textos salen de la configuración', function () {
    config([
        'kore-calendar.translations.scope_this' => 'Only this event',
        'kore-calendar.translations.scope_following' => 'This and following',
        'kore-calendar.translations.scope_all' => 'All events',
        'kore-calendar.translations.scope_question' => '“:title” repeats.',
        'kore-calendar.translations.scope_move' => 'Move a repeating event',
        'kore-calendar.translations.form_cancel' => 'Cancel',
    ]);

    Livewire::test(RecurrenceScope::class, rsoArgs())
        ->assertSee('Only this event')->assertSee('This and following')->assertSee('All events')
        ->assertSee('“Standup” repeats.')->assertSee('Move a repeating event')->assertSee('Cancel')
        ->assertDontSee('Solo este evento');
});

it('elegir devuelve el alcance al calendario destino y cierra TODA la pila', function (string $scope) {
    $t = Livewire::test(RecurrenceScope::class, rsoArgs())
        ->call('choose', $scope)
        ->assertDispatched('kore:close', force: true);

    expect(rsoDispatched($t, RecurrenceScope::CHOSEN))->toBe(['calendar' => RS_CAL, 'scope' => $scope]);
})->with(['this', 'following', 'all']);

it('un alcance que no existe no se devuelve', function () {
    Livewire::test(RecurrenceScope::class, rsoArgs())
        ->call('choose', 'todas')
        ->call('choose', '')
        ->assertNotDispatched(RecurrenceScope::CHOSEN)
        ->assertNotDispatched('kore:close');
});

it('cancelar avisa y cierra solo este diálogo: debajo puede haber un formulario', function () {
    $t = Livewire::test(RecurrenceScope::class, rsoArgs())
        ->call('cancel')
        ->assertDispatched('kore:close')
        ->assertNotDispatched(RecurrenceScope::CHOSEN);

    expect(rsoDispatched($t, RecurrenceScope::CANCELLED))->toBe(['calendar' => RS_CAL])
        // `close()`, no `closeAll()`: sin `force`.
        ->and(rsoDispatched($t, 'kore:close'))->not->toHaveKey('force');
});

it('los avisos llevan nombres propios del paquete', function () {
    expect(RecurrenceScope::CHOSEN)->toBe('kore-calendar:recurrence-scope-chosen')
        ->and(RecurrenceScope::CANCELLED)->toBe('kore-calendar:recurrence-scope-cancelled')
        ->and(RecurrenceScope::SCOPES)->toBe(['this', 'following', 'all']);
});

it('a quién responde y qué acción es están bloqueados', function (string $propiedad, mixed $valor) {
    Livewire::test(RecurrenceScope::class, rsoArgs())->set($propiedad, $valor);
})->with([
    'calendario' => ['calendar', 'otro-calendario'],
    'acción' => ['action', 'delete'],
    'título' => ['eventTitle', 'otro'],
])->throws(CannotUpdateLockedPropertyException::class);
