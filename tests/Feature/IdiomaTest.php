<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Interaction\Messages;
use KoreUi\Calendar\Overlays\EventForm;
use KoreUi\Calendar\Overlays\RecurrenceScope;
use KoreUi\Calendar\Support\Locale;
use KoreUi\Calendar\Tests\Feature\Fixtures\FormCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\InteractiveCalendar as Cal;
use KoreUi\Calendar\Tests\Feature\Fixtures\NoHooksCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\RecurringCalendar;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\View\CalendarSettings;
use Livewire\Livewire;

/*
| Inglés o español, configurable. El idioma sale de UN sitio (Support\Locale) y manda a
| la vez en los textos (lang/ + el override `translations`) y en las fechas (Carbon
| isoFormat), de modo que nunca sale una fecha en un idioma con los botones en otro.
|
| Orden: lo que pide el calendario (`->locale()`, la prop `locale`) > `kore-calendar.locale`
| > `app()->getLocale()`. Un idioma sin textos (ni `en` ni `es`) cae a `fallback_locale`.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
    Cal::fresh();
    FormCalendar::fresh();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** El toast que se envió, o null. */
function idToast($testable): ?array
{
    $toast = collect(data_get($testable->effects, 'dispatches'))->firstWhere('name', 'kore:toast');

    return $toast['params']['toast'] ?? null;
}

/** Los params del primer evento de Livewire con ese nombre, o null. */
function idDispatched($testable, string $name): ?array
{
    return collect(data_get($testable->effects, 'dispatches'))->firstWhere('name', $name)['params'] ?? null;
}

// ── Resolución ─────────────────────────────────────────────────────────

it('sin nada configurado, el idioma es el de la aplicación', function () {
    app()->setLocale('es');
    expect(Locale::resolve())->toBe('es');

    app()->setLocale('en');
    expect(Locale::resolve())->toBe('en');
});

it('una variante regional cuenta como su idioma base', function (string $pedido, string $esperado) {
    app()->setLocale($pedido);

    expect(Locale::resolve())->toBe($esperado)
        ->and(CalendarSettings::make()->locale)->toBe($esperado);
})->with([
    ['es_MX', 'es'], ['es-AR', 'es'], ['ES', 'es'], ['en_GB', 'en'], ['en-US', 'en'],
]);

it('`kore-calendar.locale` gana al idioma de la aplicación y lo pedido al calendario gana a los dos', function () {
    app()->setLocale('es');
    config(['kore-calendar.locale' => 'en']);

    expect(Locale::resolve())->toBe('en')
        ->and(CalendarSettings::make()->locale)->toBe('en')
        ->and(CalendarSettings::make(['locale' => 'es'])->locale)->toBe('es')
        // Un null pedido no cuenta: sigue la cascada.
        ->and(CalendarSettings::make(['locale' => null])->locale)->toBe('en');
});

it('un idioma sin textos cae a `fallback_locale` (en, salvo que se diga otra cosa)', function () {
    app()->setLocale('fr');

    expect(Locale::resolve())->toBe('en')
        ->and(Messages::get('today'))->toBe('Today');

    config(['kore-calendar.fallback_locale' => 'es']);

    expect(Locale::resolve())->toBe('es')
        ->and(Messages::get('today'))->toBe('Hoy');

    // Y un fallback que tampoco existe no deja el calendario sin textos.
    config(['kore-calendar.fallback_locale' => 'de']);

    expect(Locale::resolve())->toBe('en');
});

it('las reglas de región siguen al idioma pedido y no al reducido: en_GB cuenta las semanas como ISO', function () {
    app()->setLocale('en_GB');
    $gb = CalendarSettings::make(['week_numbers' => 'locale']);

    app()->setLocale('en_US');
    $us = CalendarSettings::make(['week_numbers' => 'locale']);

    expect($gb->locale)->toBe('en')
        ->and($us->locale)->toBe('en')
        ->and($gb->weekNumbers->doy)->toBe(4)
        ->and($us->weekNumbers->doy)->toBe(6);
});

// ── Textos ─────────────────────────────────────────────────────────────

it('Messages lee el archivo de idioma pedido, con sus marcadores', function () {
    expect(Messages::get('more', ['count' => 3], 'en'))->toBe('+3 more')
        ->and(Messages::get('more', ['count' => 3], 'es'))->toBe('+3 más')
        ->and(Messages::get('overlap_rejected', ['title' => 'Cita'], 'en'))->toBe('Overlaps with “Cita”')
        ->and(Messages::get('overlap_rejected', ['title' => 'Cita'], 'es_MX'))->toBe('Se solapa con «Cita»')
        // Sin idioma pedido, el resuelto.
        ->and(Messages::get('today'))->toBe('Today')
        // Una clave que no existe sale con su nombre.
        ->and(Messages::get('no_existe', [], 'en'))->toBe('no_existe');
});

it('un marcador dentro de un título no se vuelve a sustituir', function () {
    expect(Messages::get('event_moved', ['title' => 'Cita :date', 'date' => 'Oct 16'], 'en'))->toBe('“Cita :date” moved to Oct 16');
});

it('el override del config gana al archivo de idioma, en todos los idiomas', function () {
    config(['kore-calendar.translations' => ['today' => 'Right now']]);

    expect(Messages::get('today', [], 'en'))->toBe('Right now')
        ->and(Messages::get('today', [], 'es'))->toBe('Right now')
        // Y solo esa clave.
        ->and(Messages::get('next', [], 'en'))->toBe('Next');
});

it('un override por idioma solo vale en ese idioma y gana al general', function () {
    config(['kore-calendar.translations' => [
        'today' => 'Ahora',
        'en' => ['today' => 'Right now'],
    ]]);

    expect(Messages::get('today', [], 'en'))->toBe('Right now')
        ->and(Messages::get('today', [], 'es'))->toBe('Ahora');

    config(['kore-calendar.translations' => ['en' => ['today' => 'Right now']]]);

    expect(Messages::get('today', [], 'es'))->toBe('Hoy');
});

it('el override llega a las vistas del calendario', function () {
    app()->setLocale('en');
    config(['kore-calendar.translations.today' => 'Right now']);

    $html = (string) $this->blade('<x-kore::calendar timezone="UTC" date="2026-10-16" />');

    expect($html)->toContain('Right now')->not->toContain('>Today<');
});

// ── Vistas: textos y fechas en el mismo idioma ─────────────────────────

it('en inglés, el calendario muestra textos y fechas en inglés', function () {
    app()->setLocale('en');

    $html = (string) $this->blade('<x-kore::calendar timezone="UTC" date="2026-10-16" />');

    expect($html)
        ->toContain('Today')->toContain('Previous')->toContain('Next')->toContain('Month')->toContain('Week')
        ->toContain('October 2026')
        ->toContain('Friday, October 16, 2026')
        ->not->toContain('Hoy')->not->toContain('Anterior')->not->toContain('octubre');
});

it('en español, el calendario muestra textos y fechas en español', function () {
    app()->setLocale('es');

    $html = (string) $this->blade('<x-kore::calendar timezone="UTC" date="2026-10-16" />');

    expect($html)
        ->toContain('Hoy')->toContain('Anterior')->toContain('Siguiente')->toContain('Mes')->toContain('Semana')
        ->toContain('Octubre 2026')
        ->toContain('viernes, 16 de octubre de 2026')
        ->not->toContain('Today')->not->toContain('Previous')->not->toContain('October');
});

it('la prop `locale` de la etiqueta gana al idioma de la aplicación, en textos y fechas', function () {
    app()->setLocale('es');

    $html = (string) $this->blade('<x-kore::calendar timezone="UTC" date="2026-10-16" locale="en" />');

    expect($html)->toContain('Today')->toContain('October 2026')->not->toContain('Hoy')->not->toContain('octubre');
});

it('un idioma sin textos muestra el de reserva, también en las fechas', function () {
    app()->setLocale('fr');

    $html = (string) $this->blade('<x-kore::calendar timezone="UTC" date="2026-10-16" />');

    expect($html)->toContain('Today')->toContain('October 2026')->not->toContain('octobre')->not->toContain('Aujourd');
});

it('la hora del evento sigue al idioma: 12 h en inglés, 24 h en español', function () {
    $evento = ['id' => 1, 'title' => 'Reunión', 'start' => '2026-10-16 14:30', 'end' => '2026-10-16 15:30'];

    app()->setLocale('en');
    $en = (string) $this->blade('<x-kore::calendar view="week" timezone="UTC" date="2026-10-16" :events="$e" />', ['e' => [$evento]]);

    app()->setLocale('es');
    $es = (string) $this->blade('<x-kore::calendar view="week" timezone="UTC" date="2026-10-16" :events="$e" />', ['e' => [$evento]]);

    expect($en)->toContain('2:30 PM')->not->toContain('14:30')
        ->and($es)->toContain('14:30')->not->toContain('2:30 PM');
});

it('el aviso de un evento en conflicto cita los títulos con las comillas del idioma', function () {
    $eventos = [
        ['id' => 1, 'title' => 'Uno', 'start' => '2026-10-16 10:00', 'end' => '2026-10-16 11:00', 'resourceIds' => ['a']],
        ['id' => 2, 'title' => 'Dos', 'start' => '2026-10-16 10:30', 'end' => '2026-10-16 11:30', 'resourceIds' => ['a']],
    ];
    $render = fn () => (string) $this->blade(
        '<x-kore::calendar view="resource-day" timezone="UTC" date="2026-10-16" :events="$e" :resources="$r" :highlight-conflicts="true" />',
        ['e' => $eventos, 'r' => [['id' => 'a', 'title' => 'Sala']]],
    );

    app()->setLocale('en');
    expect($render())->toContain('Conflicts with “Dos”');

    app()->setLocale('es');
    expect($render())->toContain('En conflicto con «Dos»');
});

// ── Livewire: los textos que viajan al JS ──────────────────────────────

it('los anuncios del teclado que recibe el JS salen en el idioma del calendario', function () {
    app()->setLocale('es');
    Cal::$config = ['locale' => 'en'];

    $html = Livewire::test(Cal::class)->html();

    expect($html)
        ->toContain('Picked up.')->toContain('Canceled.')->toContain('now ends on :date')
        ->toContain('Press Space to pick up and move with the keyboard.')
        // El JS formatea la fecha con el mismo idioma (Intl).
        ->toContain('\u0022locale\u0022:\u0022en\u0022')
        ->not->toContain('Agarrado')->not->toContain('Cancelado')->not->toContain('Espacio para mover');
});

it('y en español, con la aplicación en español', function () {
    app()->setLocale('es');

    $html = Livewire::test(Cal::class)->html();

    expect($html)
        ->toContain('Agarrado.')->toContain('Cancelado.')->toContain('ahora termina :date')
        ->toContain('Espacio para mover con el teclado')
        ->toContain('\u0022locale\u0022:\u0022es\u0022')
        ->not->toContain('Picked up');
});

it('los motivos de rechazo y los anuncios de una acción salen en el idioma del calendario, no en el de la aplicación', function () {
    app()->setLocale('es');
    Cal::$config = ['locale' => 'en'];

    $rechazo = Livewire::test(Cal::class)->call('moveEvent', 999, Marker::parse('2026-10-15 14:00')->ts, false);

    expect(idToast($rechazo))->toMatchArray(['type' => 'error', 'title' => 'Could not move the event', 'description' => 'The event is no longer available']);

    // Un movimiento aceptado: la fecha del anuncio y el texto, en inglés.
    Livewire::test(Cal::class)
        ->call('moveEvent', 1, Marker::parse('2026-10-15 14:00')->ts, false)
        ->assertReturned(['ok' => true, 'message' => '“Revisión” moved to October 15, 2026 2:00 PM']);
});

it('la misma acción, con el calendario en español, habla español', function () {
    app()->setLocale('en');
    Cal::$config = ['locale' => 'es'];

    $t = Livewire::test(Cal::class)->call('moveEvent', 999, Marker::parse('2026-10-15 14:00')->ts, false);

    expect(idToast($t))->toMatchArray(['title' => 'No se pudo mover el evento', 'description' => 'El evento ya no está disponible']);
});

it('el motivo de una regla que rechaza (con el título entre las comillas del idioma) sale en el idioma del calendario', function () {
    Cal::$config = ['locale' => 'en', 'interaction.event_overlap' => false];

    // El viernes 16 hay un día completo («Congreso») y «Bloqueo» (overlap: false).
    $t = Livewire::test(Cal::class)->call('moveEvent', 1, Marker::parse('2026-10-16 15:00')->ts, false);

    expect(idToast($t)['title'])->toBe('Could not move the event')
        ->and(idToast($t)['description'])->toMatch('/^Overlaps with “(Congreso|Bloqueo)”$/');
});

it('un hook sin implementar explica qué falta en el idioma del calendario', function () {
    app()->setLocale('en');

    $t = Livewire::test(NoHooksCalendar::class);
    $t->call('selectRange', Marker::parse('2026-10-15 10:00')->ts, Marker::parse('2026-10-15 11:00')->ts, false);

    // El formulario lo abre el propio calendario; el motivo sale al intentar guardar.
    $guardar = $t->dispatch(EventForm::SAVED, calendar: $t->id(), data: [
        'mode' => 'create', 'title' => 'x', 'allDay' => false, 'start' => '2026-10-15 10:00:00', 'end' => '2026-10-15 11:00:00',
    ]);

    expect(idToast($guardar))->toMatchArray([
        'title' => 'Could not create the event',
        'description' => 'This calendar does not save that change yet: implement createEvent() in '.NoHooksCalendar::class.'.',
    ]);
});

it('el calendario abre el formulario y el diálogo de alcance con SU idioma, ya resuelto', function () {
    app()->setLocale('fr');
    FormCalendar::$config = ['locale' => 'es_MX'];

    $form = Livewire::test(FormCalendar::class)->call('clickEvent', 1);
    $abrir = idDispatched($form, 'kore:open');

    expect($abrir['arguments']['locale'])->toBe('es')
        ->and($abrir['overlayAttributes']['title'])->toBe('Editar evento');

    // Sin idioma pedido, el de la aplicación no soportado cae al de reserva.
    FormCalendar::$config = [];
    $abrir = idDispatched(Livewire::test(FormCalendar::class)->call('clickEvent', 1), 'kore:open');

    expect($abrir['arguments']['locale'])->toBe('en')
        ->and($abrir['overlayAttributes']['title'])->toBe('Edit event');
});

it('mover una ocurrencia abre el diálogo de alcance con el idioma del calendario', function () {
    app()->setLocale('es');
    RecurringCalendar::fresh();
    RecurringCalendar::$config = ['locale' => 'en'];

    $t = Livewire::test(RecurringCalendar::class)->call('moveEvent', 's:'.Marker::parse('2026-10-13 09:00')->ts, Marker::parse('2026-10-13 11:00')->ts, false);
    $abrir = idDispatched($t, 'kore:open');

    expect($abrir['name'])->toBe('kore-calendar-recurrence-scope')
        ->and($abrir['arguments']['locale'])->toBe('en')
        ->and($abrir['overlayAttributes']['title'])->toBe('Move a recurring event');
});

// ── Overlays: el idioma del calendario que los abrió ───────────────────

it('el formulario habla el idioma con el que lo abrió el calendario, aunque la aplicación hable otro', function () {
    app()->setLocale('es');

    Livewire::test(EventForm::class, ['calendar' => 'c', 'mode' => 'edit', 'eventId' => 1, 'title' => 'Revisión', 'start' => '2026-10-15 10:00:00', 'end' => '2026-10-15 11:00:00', 'locale' => 'en'])
        ->assertSet('calendarLocale', 'en')
        ->assertSee('Edit event')->assertSee('Start date')->assertSee('Pick a date')->assertSee('Save')->assertSee('Delete')->assertSee('Palette 1')
        ->assertDontSee('Guardar')->assertDontSee('Eliminar');
});

it('el formulario sin idioma usa el resuelto (variante regional o idioma sin textos incluidos)', function () {
    app()->setLocale('es_MX');
    Livewire::test(EventForm::class, ['calendar' => 'c', 'start' => '2026-10-15 10:00:00'])
        ->assertSet('calendarLocale', 'es')->assertSee('Nuevo evento');

    app()->setLocale('fr');
    Livewire::test(EventForm::class, ['calendar' => 'c', 'start' => '2026-10-15 10:00:00'])
        ->assertSet('calendarLocale', 'en')->assertSee('New event');
});

it('los errores del formulario y la confirmación de eliminar salen en su idioma', function () {
    app()->setLocale('es');

    $t = Livewire::test(EventForm::class, ['calendar' => 'c', 'mode' => 'edit', 'eventId' => 7, 'title' => 'Revisión', 'start' => '2026-10-15 10:00:00', 'end' => '2026-10-15 11:00:00', 'locale' => 'en'])
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);

    expect($t->errors()->first('title'))->toBe('This field is required');

    $fin = Livewire::test(EventForm::class, ['calendar' => 'c', 'start' => '2026-10-15 10:00:00', 'end' => '2026-10-15 09:00:00', 'locale' => 'en'])
        ->set('title', 'x')
        ->call('save');

    expect($fin->errors()->first('endTime'))->toBe('The end cannot be before the start');

    $confirmar = Livewire::test(EventForm::class, ['calendar' => 'c', 'mode' => 'edit', 'eventId' => 7, 'title' => 'Revisión', 'start' => '2026-10-15 10:00:00', 'locale' => 'en'])
        ->call('delete');
    $dialogo = idDispatched($confirmar, 'kore:open')['arguments'];

    expect($dialogo)->toMatchArray([
        'title' => 'Delete “Revisión”?',
        'description' => 'This action cannot be undone.',
        'confirmText' => 'Delete',
        'cancelText' => 'Cancel',
    ]);
});

it('el diálogo de alcance habla el idioma con el que lo abrió el calendario', function () {
    app()->setLocale('es');

    Livewire::test(RecurrenceScope::class, ['calendar' => 'c', 'action' => 'delete', 'title' => 'Standup', 'locale' => 'en'])
        ->assertSet('calendarLocale', 'en')
        ->assertSee('Delete a recurring event')
        ->assertSee('“Standup” is a recurring event.')
        ->assertSee('Only this event')->assertSee('This and following events')->assertSee('All events')->assertSee('Cancel')
        ->assertDontSee('Solo este evento');

    // Sin idioma: el de la aplicación.
    Livewire::test(RecurrenceScope::class, ['calendar' => 'c', 'title' => 'Standup'])
        ->assertSet('calendarLocale', 'es')->assertSee('Solo este evento');
});
