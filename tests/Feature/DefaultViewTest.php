<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Tests\Feature\Fixtures\TeamCalendar;
use KoreUi\Calendar\Tests\Feature\Fixtures\WeekDefaultCalendar;
use KoreUi\Calendar\View\CalendarViews;
use Livewire\Livewire;

/*
| La vista inicial, en <x-kore::calendar> y en KoreCalendar. En los dos la cadena es la
| misma: lo que pide el usuario (URL) o la etiqueta, después `default_view` del config y,
| por último, la primera de las vistas que el calendario ofrece. Cada eslabón solo vale
| si la vista está entre las disponibles; si no, se pasa al siguiente.
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-14 10:00:00 UTC');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** La vista que marca la barra como actual (aria-current="page") en el modo de enlaces. */
function vistaActiva(string $html): ?string
{
    preg_match_all('/<a\b[^>]*>/s', $html, $enlaces);

    foreach ($enlaces[0] as $enlace) {
        if (str_contains($enlace, 'aria-current="page"') && preg_match('/[?&;]view=([a-z-]+)/', $enlace, $m)) {
            return $m[1];
        }
    }

    return null;
}

// ── <x-kore::calendar> ───────────────────────────────────────────────

it('el componente anónimo arranca en default_view del config', function () {
    config(['kore-calendar.default_view' => 'week']);

    $html = (string) $this->blade('<x-kore::calendar timezone="UTC" />');

    expect(vistaActiva($html))->toBe('week');
});

it('la prop view gana a default_view', function () {
    config(['kore-calendar.default_view' => 'week']);

    expect(vistaActiva((string) $this->blade('<x-kore::calendar view="list" timezone="UTC" />')))->toBe('list');
});

it('el ?view= de la URL gana a la prop y a default_view, como hasta ahora', function () {
    config(['kore-calendar.default_view' => 'week']);
    request()->merge(['view' => 'day']);

    expect(vistaActiva((string) $this->blade('<x-kore::calendar view="list" timezone="UTC" />')))->toBe('day');
});

it('un ?view= que no es del selector se ignora y se sigue con la prop o default_view', function () {
    config(['kore-calendar.default_view' => 'week']);
    request()->merge(['view' => 'inventada']);

    expect(vistaActiva((string) $this->blade('<x-kore::calendar timezone="UTC" />')))->toBe('week')
        ->and(vistaActiva((string) $this->blade('<x-kore::calendar view="list" timezone="UTC" />')))->toBe('list');
});

it('sin default_view en el selector se usa la primera de views, igual que KoreCalendar', function () {
    config(['kore-calendar.default_view' => 'week']);

    // `week` no está en este selector: la primera es `list`.
    $html = (string) $this->blade('<x-kore::calendar :views="[\'list\', \'month\']" timezone="UTC" />');

    expect(vistaActiva($html))->toBe('list');
});

it('default_view de recursos sin recursos cuenta como no disponible', function () {
    config(['kore-calendar.default_view' => 'resource-week']);

    expect(vistaActiva((string) $this->blade('<x-kore::calendar timezone="UTC" />')))->toBe('month');

    $con = (string) $this->blade('<x-kore::calendar :resources="[[\'id\' => \'a\', \'title\' => \'Sala\']]" timezone="UTC" />');

    expect(vistaActiva($con))->toBe('resource-week');
});

it('una prop view que el selector no ofrece cae a default_view, no se pinta a la fuerza', function () {
    config(['kore-calendar.default_view' => 'week']);

    $html = (string) $this->blade('<x-kore::calendar view="day" :views="[\'month\', \'week\']" timezone="UTC" />');

    expect(vistaActiva($html))->toBe('week');
});

it('con la barra apagada, default_view también manda (no hay URL a la que hacer caso)', function () {
    config(['kore-calendar.default_view' => 'list']);
    request()->merge(['view' => 'day']);

    $html = (string) $this->blade('<x-kore::calendar :toolbar="false" timezone="UTC" />');

    // La agenda vacía se anuncia con role="status"; el mes (lo de antes) es una tabla.
    expect($html)->toContain('No hay eventos')->not->toContain('role="table"');
});

// ── CalendarViews::initial ────────────────────────────────────────────

it('initial() elige el primer candidato disponible y, si no hay, la primera vista o month', function () {
    expect(CalendarViews::initial(['x', 'week', 'list'], ['month', 'list', 'week']))->toBe('week')
        ->and(CalendarViews::initial([null, 'day'], ['month', 'list']))->toBe('month')
        ->and(CalendarViews::initial(['day'], []))->toBe('month');
});

// ── KoreCalendar ──────────────────────────────────────────────────────

it('KoreCalendar arranca en default_view del config', function () {
    config(['kore-calendar.default_view' => 'week']);

    Livewire::test(TeamCalendar::class)
        ->assertDontSee('Octubre 2026')
        ->assertSeeHtml('>8:00<')
        ->assertSeeHtml('wire:click="changeView(\'week\')"')
        ->assertSet('view', '');
});

it('el defaultView() de configure() gana al del config', function () {
    config(['kore-calendar.default_view' => 'list']);

    Livewire::test(WeekDefaultCalendar::class)->assertSeeHtml('>9:00<')->assertDontSee('Octubre 2026');
});

it('una default_view que KoreCalendar no ofrece cae a la primera de sus views', function () {
    // TeamCalendar ofrece month, week, list y resource-day; `timeline-week` no.
    config(['kore-calendar.default_view' => 'timeline-week']);

    Livewire::test(TeamCalendar::class)->assertSee('Octubre 2026');
});

it('el ?view= de la URL gana a default_view, y uno inventado cae a default_view', function () {
    config(['kore-calendar.default_view' => 'week']);

    Livewire::withQueryParams(['view' => 'list'])->test(TeamCalendar::class)
        ->assertDontSeeHtml('role="table"');

    Livewire::withQueryParams(['view' => 'inventada'])->test(TeamCalendar::class)
        ->assertDontSee('Octubre 2026')
        ->assertSeeHtml('>8:00<');
});
