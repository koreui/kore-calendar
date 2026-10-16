<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Tests\Feature\Fixtures\InteractiveCalendar as Cal;
use Livewire\Livewire;

/*
| El texto de ayuda del teclado («Espacio para mover…») al que apuntan los eventos
| movibles con aria-describedby. Un id fijo se repetía con dos calendarios en la
| página, y el segundo apuntaba al texto del primero.
*/

beforeEach(function () {
    app()->setLocale('es');
    CarbonImmutable::setTestNow('2026-10-14 10:00:00 UTC');
    Cal::fresh();
    Livewire::component('calendario-a', Cal::class);
    Livewire::component('calendario-b', Cal::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{help: list<string>, described: list<string>} Los ids de los textos de ayuda y los que citan los eventos. */
function ayudasDe(string $html): array
{
    preg_match_all('/<p\s+id="(kore-calendar-kbd-help[^"]*)"/', $html, $ayudas);
    preg_match_all('/aria-describedby="(kore-calendar-kbd-help[^"]*)"/', $html, $citados);

    return ['help' => $ayudas[1], 'described' => $citados[1]];
}

it('cada evento movible apunta a un texto de ayuda que existe', function () {
    ['help' => $ayuda, 'described' => $citados] = ayudasDe(Livewire::test(Cal::class)->html());

    expect($ayuda)->toHaveCount(1)
        ->and($citados)->not->toBeEmpty()
        ->and(array_unique($citados))->toBe([$citados[0]])
        ->and($citados[0])->toBe($ayuda[0]);
});

it('el id ya no es fijo: lleva el del calendario', function () {
    $id = ayudasDe(Livewire::test(Cal::class)->html())['help'][0];

    expect($id)->not->toBe('kore-calendar-kbd-help')
        ->and($id)->toStartWith('kore-calendar-kbd-help-');
});

it('dos calendarios en la misma página tienen cada uno su texto, y sus eventos apuntan al suyo', function () {
    $html = (string) $this->blade('<div id="uno"><livewire:calendario-a /></div><div id="dos"><livewire:calendario-b /></div>');

    preg_match('/<div id="uno">(.*)<\/div><div id="dos">(.*)<\/div>$/s', trim($html), $m);
    $uno = ayudasDe($m[1]);
    $dos = ayudasDe($m[2]);

    expect($uno['help'])->toHaveCount(1)
        ->and($dos['help'])->toHaveCount(1)
        ->and($uno['help'][0])->not->toBe($dos['help'][0])
        ->and(array_unique($uno['described']))->toBe([$uno['help'][0]])
        ->and(array_unique($dos['described']))->toBe([$dos['help'][0]]);

    // Ningún id repetido en toda la página: lo que un validador de HTML (y los lectores de pantalla) piden.
    $todos = array_merge($uno['help'], $dos['help']);
    expect(array_unique($todos))->toHaveCount(2);
});

it('el id es estable entre renders del mismo calendario (el morph no lo cambia)', function () {
    $calendario = Livewire::test(Cal::class);
    $antes = ayudasDe($calendario->html())['help'][0];

    $calendario->call('next')->call('previous');

    expect(ayudasDe($calendario->html())['help'][0])->toBe($antes);
});

it('fuera de Livewire, dos calendarios pintados a mano también tienen ids distintos', function () {
    $nuevo = fn () => KoreUi\Calendar\View\CalendarRenderer::render(KoreUi\Calendar\View\CalendarSettings::make(['timezone' => 'UTC']));
    $a = $nuevo();
    $b = $nuevo();

    expect($a->keyboardHelpId())->not->toBe($b->keyboardHelpId())
        // Y el de uno es siempre el mismo: el texto y los eventos de un calendario coinciden.
        ->and($a->keyboardHelpId())->toBe($a->keyboardHelpId());
});

it('la plantilla de «ahora termina» llega al cliente para anunciar el fin al redimensionar', function () {
    $html = Livewire::test(Cal::class)->html();

    // Los marcadores viajan sin sustituir: los rellena el JS con el título y el fin nuevos.
    expect($html)->toContain('ahora termina :date')->toContain(':title');

    config(['kore-calendar.translations.event_resized' => 'Now ends :date (:title)']);

    expect(Livewire::test(Cal::class)->html())->toContain('Now ends :date (:title)');
});
