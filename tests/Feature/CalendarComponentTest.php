<?php

use Carbon\CarbonImmutable;

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-16 10:00:00 UTC');
});
afterEach(fn () => CarbonImmutable::setTestNow());

it('<x-kore::calendar> funciona sin Livewire, con enlaces de navegación', function () {
    $html = (string) $this->blade('<x-kore::calendar :events="$events" timezone="UTC" />', [
        'events' => [['id' => 1, 'title' => 'Congreso', 'start' => '2026-10-14', 'end' => '2026-10-16']],
    ]);

    expect($html)->toContain('Congreso')
        ->toContain('Octubre 2026')
        ->toContain('date=2026-11-01')
        ->toContain('view=week')
        ->not->toContain('wire:click');
});

it('sin toolbar no hay barra', function () {
    expect((string) $this->blade('<x-kore::calendar :toolbar="false" timezone="UTC" />'))->not->toContain('aria-live="polite"');
});

it('las props ganan a la configuración', function () {
    $html = (string) $this->blade('<x-kore::calendar view="week" slot-min-time="09:00" slot-max-time="12:00" timezone="UTC" :toolbar="false" />');

    expect($html)->toContain('>9:00<')->toContain('>11:00<')->not->toContain('>8:00<')->not->toContain('>12:00<');
});

it('con recursos ofrece sus vistas; sin ellos, no', function () {
    $con = (string) $this->blade('<x-kore::calendar :resources="[[\'id\' => \'a\', \'title\' => \'Sala\']]" timezone="UTC" />');
    $sin = (string) $this->blade('<x-kore::calendar timezone="UTC" />');

    expect($con)->toContain('view=resource-week')->and($sin)->not->toContain('view=resource-week');
});

it('el timeline sin Livewire pinta todas las filas aunque pasen de virtualize_after', function () {
    // En modo enlaces no hay servidor que dé la ventana siguiente al hacer scroll
    // (live=false): virtualizar dejaba las filas 61 a 150 fuera para siempre.
    $recursos = array_map(fn ($i) => ['id' => "r{$i}", 'title' => sprintf('Recurso %03d', $i)], range(1, 150));

    $html = (string) $this->blade('<x-kore::calendar view="timeline-day" date="2026-10-14" timezone="UTC" :resources="$recursos" />', ['recursos' => $recursos]);

    expect(config('kore-calendar.timeline.virtualize_after'))->toBe(100)
        ->and(substr_count($html, 'kore-calendar-timeline-lane'))->toBe(150)
        ->and($html)->toContain('Recurso 001')->toContain('Recurso 150')
        ->and($html)->toContain('data-total="150"')->toContain('data-row-count="150"')
        ->and($html)->not->toContain('data-kore-timeline-spacer')
        ->and($html)->toContain('live: false');
});

it('KoreCalendar sí virtualiza: tiene servidor al que pedir la ventana siguiente', function () {
    // El contrapunto del anterior: el timeline con Livewire sigue montando una ventana.
    \Livewire\Livewire::withQueryParams(['view' => 'timeline-day', 'date' => '2026-10-14'])
        ->test(\KoreUi\Calendar\Tests\Feature\Fixtures\ManyResourcesCalendar::class)
        ->assertSeeHtml('data-total="150"')
        ->assertSeeHtml('data-row-count="60"')
        ->assertSeeHtml('data-kore-timeline-spacer')
        ->assertSeeHtml('live: true');
});
