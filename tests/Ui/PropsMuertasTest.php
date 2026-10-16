<?php

use Symfony\Component\Finder\Finder;

/**
 * Ninguna prop declarada que la vista no lea.
 *
 * Una prop en el `@props` es una promesa: quien la escribe en la etiqueta espera
 * que pase algo. Cuando no la lee nadie no hay error ni aviso —Blade la acepta y
 * la tira—, así que el único síntoma es que el componente no hace lo que dice su
 * documentación, y eso solo se ve leyendo la plantilla entera.
 *
 * En kore-ui ya pasó tres veces: `bordered` en `table`, `inline` en `field` y el
 * `perPage` de `table.pagination`, las tres documentadas como si funcionaran.
 *
 * Ojo con lo que cuenta como uso: `$perPage = $perPage ?: config(...)` menciona
 * la variable pero no la usa para nada, así que las líneas que solo resuelven la
 * prop contra sí misma no valen.
 */
it('ninguna prop declarada se queda sin leer', function () {
    // Props que la vista no menciona y que aun así están vivas, por vista
    // (`'calendar/week.blade.php' => ['prop']`). Un `@include` hereda las
    // variables del padre, y `@aware` las lee de él sin que el padre haga nada.
    // El calendario todavía no tiene ninguna.
    $exentas = [];

    $problemas = [];

    $vistas = Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php');

    foreach ($vistas as $vista) {
        $contenido = $vista->getContents();
        $relativa = str_replace(realpath(__DIR__.'/../../').'/resources/views/', '', $vista->getRealPath());

        if (! preg_match('/@props\(\[(.*?)\n\]\)/s', $contenido, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $declaracion = $m[1][0];
        $cuerpo = substr($contenido, $m[0][1] + strlen($m[0][0]));

        // Mencionar una prop en un comentario no es usarla.
        $cuerpo = preg_replace('/\{\{--.*?--\}\}/s', '', $cuerpo);
        $cuerpo = preg_replace('/^\s*\/\/.*$/m', '', $cuerpo);

        foreach (preg_match_all("/'(\w+)'\s*=>/", $declaracion, $props) ? $props[1] : [] as $prop) {
            if (in_array($prop, $exentas[$relativa] ?? [], true)) {
                continue;
            }

            // Fuera las líneas que solo resuelven la prop contra sí misma.
            $util = preg_replace('/^\s*\$'.$prop.'\s*=\s*\$'.$prop.'\s*(\?\?|\?:).*$/m', '', $cuerpo);

            if (preg_match('/\$'.$prop.'\b/', $util)) {
                continue;
            }

            // También se puede consumir como slot (`isset($x)`) o citarse por su
            // nombre en un `except([...])`.
            $kebab = strtolower(preg_replace('/(?<!^)(?=[A-Z])/', '-', $prop));

            if (str_contains($util, "'$kebab'") || str_contains($util, "'$prop'")) {
                continue;
            }

            $problemas[] = sprintf('%s  $%s', $relativa, $prop);
        }
    }

    expect($problemas)->toBe([], implode("\n", array_merge(
        ['Props declaradas que la vista no lee:'],
        $problemas,
        ['', 'O se implementan, o se quitan del @props y de su documentación.'],
    )));
});

/**
 * Ni ninguna clave de traducción que no lea nadie.
 *
 * Es el mismo defecto una capa más abajo: en kore-ui, `toggle_period` se añadió a
 * la configuración en la 2.0.0 —«Cambiar entre AM y PM»— y no se llegó a
 * conectar, así que el botón siguió anunciándose «AM» durante dos versiones
 * mientras su texto esperaba en `config/kore-ui.php`. Una clave que no lee nadie
 * es una promesa de traducción que no se cumple.
 *
 * Aquí las claves son las de `lang/{es,en}/calendar.php`.
 */
it('ninguna clave de traducción se queda sin usar', function () {
    // Las que se piden con el nombre construido al vuelo. El grep no las ve, y
    // el prefijo o el sufijo están en el código de al lado. Si una vista monta
    // una clave así (`'presence_'.$presence`), se apunta aquí su prefijo.
    // `view_`: el toolbar pide `'view_'.$view`.
    $dinamicas = ['view_'];

    // Las claves de los dos idiomas (que tengan las mismas lo comprueba IdiomasTest).
    $claves = [];
    foreach (glob(__DIR__.'/../../lang/*/calendar.php') as $archivo) {
        $claves = array_merge($claves, array_keys(require $archivo));
    }
    $claves = array_values(array_unique($claves));

    // Si el glob deja de encontrar los archivos, el test pasaría sin mirar nada.
    expect($claves)->not->toBeEmpty('no se encuentran los archivos lang/*/calendar.php');

    $fuentes = '';
    foreach ([
        __DIR__.'/../../resources/views',
        __DIR__.'/../../resources/js',
        __DIR__.'/../../src',
    ] as $raiz) {
        foreach (Finder::create()->files()->in($raiz) as $archivo) {
            $fuentes .= $archivo->getContents();
        }
    }

    $huerfanas = [];

    foreach ($claves as $clave) {
        foreach ($dinamicas as $prefijo) {
            if (str_starts_with($clave, $prefijo)) {
                continue 2;
            }
        }

        if (! str_contains($fuentes, "'".$clave."'") && ! str_contains($fuentes, '.'.$clave."'")) {
            $huerfanas[] = $clave;
        }
    }

    expect($huerfanas)->toBe([], implode("\n", array_merge(
        ['Claves de traducción que no lee nadie:'],
        $huerfanas,
        ['', 'O se conectan donde hacían falta, o se quitan de lang/es/calendar.php y lang/en/calendar.php.'],
    )));
});

/**
 * Y ningún comentario de Blade con un `@php` dentro.
 *
 * No es una manía de estilo: el compilador ve la directiva **dentro del
 * comentario**, abre un bloque de PHP de verdad y se traga todo lo que venga
 * detrás hasta el siguiente `@endphp`. No da error de compilación —la vista
 * renderiza— y lo que se pierde por el camino son las variables del bloque real,
 * que aparecen como «Undefined variable» en cualquier sitio menos donde está la
 * causa.
 */
it('ningún comentario de Blade abre un bloque de PHP sin querer', function () {
    $problemas = [];

    $vistas = Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php');

    foreach ($vistas as $vista) {
        $relativa = str_replace(realpath(__DIR__.'/../../').'/resources/views/', '', $vista->getRealPath());

        if (! preg_match_all('/\{\{--(.*?)--\}\}/s', $vista->getContents(), $comentarios)) {
            continue;
        }

        foreach ($comentarios[1] as $comentario) {
            if (preg_match('/@(php|endphp|verbatim|endverbatim)\b/', $comentario, $m)) {
                $problemas[] = sprintf('%s  «@%s» dentro de un comentario', $relativa, $m[1]);
            }
        }
    }

    expect($problemas)->toBe([], implode("\n", array_merge(
        ['Directivas que abren bloque, escritas dentro de un comentario de Blade:'],
        $problemas,
        ['', 'Escríbelas sin la arroba: «bloque PHP», «el @ php de arriba»…'],
    )));
});
