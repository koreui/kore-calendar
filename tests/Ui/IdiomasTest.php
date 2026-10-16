<?php

use KoreUi\Calendar\Overlays\RecurrenceScope;
use KoreUi\Calendar\Support\Locale;

/**
 * Los dos idiomas dicen lo mismo.
 *
 * Una clave que está en `lang/es` y no en `lang/en` sale en inglés con el nombre de la
 * clave (`form_save`), y una con otro marcador (`:count` por `:total`) deja la frase
 * sin el dato. Ninguna de las dos se ve hasta que alguien usa el calendario en ese idioma.
 */

/** @return array<string, string> */
function textosDe(string $idioma): array
{
    return require __DIR__."/../../lang/{$idioma}/calendar.php";
}

/** Los marcadores de un texto (`:count`, `:date`), ordenados y sin repetir. */
function marcadoresDe(string $texto): array
{
    preg_match_all('/:([a-z][a-z_]*)/i', $texto, $m);
    $marcadores = array_values(array_unique($m[1]));
    sort($marcadores);

    return $marcadores;
}

it('los idiomas del paquete son los que tienen archivo en lang/', function () {
    $carpetas = array_map('basename', glob(__DIR__.'/../../lang/*', GLOB_ONLYDIR));
    sort($carpetas);

    expect($carpetas)->toBe(Locale::SUPPORTED);
});

it('toda clave de lang/es existe en lang/en y al revés', function () {
    $es = array_keys(textosDe('es'));
    $en = array_keys(textosDe('en'));

    expect(array_values(array_diff($es, $en)))->toBe([], 'Faltan en lang/en/calendar.php')
        ->and(array_values(array_diff($en, $es)))->toBe([], 'Faltan en lang/es/calendar.php');
});

it('cada clave lleva los mismos marcadores en los dos idiomas', function () {
    $en = textosDe('en');
    $distintos = [];

    foreach (textosDe('es') as $clave => $texto) {
        if (marcadoresDe($texto) !== marcadoresDe($en[$clave] ?? '')) {
            $distintos[] = sprintf('%s  es(%s)  en(%s)', $clave, implode(',', marcadoresDe($texto)), implode(',', marcadoresDe($en[$clave] ?? '')));
        }
    }

    expect($distintos)->toBe([], implode("\n", ['Marcadores distintos entre idiomas:', ...$distintos]));
});

it('todo texto es una cadena no vacía', function () {
    foreach (['es', 'en'] as $idioma) {
        foreach (textosDe($idioma) as $clave => $texto) {
            expect($texto)->toBeString()->not->toBeEmpty("{$idioma}.{$clave}");
        }
    }
});

it('hay un texto por cada vista, cada color y cada alcance, en los dos idiomas', function () {
    $esperadas = [
        ...array_map(fn ($vista) => "view_{$vista}", config('kore-calendar.views')),
        ...array_values(KoreUi\Calendar\Interaction\EventFormPayload::COLORS),
        ...array_values(RecurrenceScope::TITLES),
        ...array_map(fn ($alcance) => "scope_{$alcance}", ['this', 'following', 'all']),
    ];

    foreach (['es', 'en'] as $idioma) {
        expect(array_values(array_diff($esperadas, array_keys(textosDe($idioma)))))->toBe([], "Faltan en lang/{$idioma}");
    }
});

it('ningún texto del inglés se quedó sin traducir (los iguales en los dos idiomas están apuntados)', function () {
    // Las que son la misma palabra en los dos idiomas.
    $iguales = ['view_list', 'form_color'];

    $es = textosDe('es');
    $sinTraducir = [];

    foreach (textosDe('en') as $clave => $texto) {
        if ($texto === $es[$clave] && ! in_array($clave, $iguales, true)) {
            $sinTraducir[] = $clave;
        }
    }

    expect($sinTraducir)->toBe([], 'Textos iguales en los dos idiomas: ¿falta traducir?');
});
