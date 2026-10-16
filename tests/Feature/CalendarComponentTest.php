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
