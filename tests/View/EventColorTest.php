<?php

use KoreUi\Calendar\View\EventColor;

it('resuelve los tokens de kore-ui a su variable, para que cambien con el tema', function () {
    expect(EventColor::css('primary'))->toBe('var(--kore-primary)')
        ->and(EventColor::css('chart-3'))->toBe('var(--kore-chart-3)')
        ->and(EventColor::style('success'))->toBe('--kore-event: var(--kore-success);');
});

it('acepta colores CSS reales', function (string $color) {
    expect(EventColor::css($color))->toBe($color);
})->with(['#f00', '#ff8800', 'oklch(0.7 0.15 30)', 'rgb(10, 20, 30)', 'tomato']);

it('rechaza lo que no es un color, porque acaba en un atributo style', function (string $color) {
    expect(EventColor::css($color))->toBeNull()->and(EventColor::style($color))->toBe('');
})->with(['red; background: url(x)', 'chart-9', 'var(--x)', 'expression(alert(1))', '"><script>']);

it('sin color no pone nada', function () {
    expect(EventColor::style(null))->toBe('')->and(EventColor::style(''))->toBe('');
});
