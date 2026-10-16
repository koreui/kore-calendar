<?php

use Symfony\Component\Finder\Finder;

/**
 * Un `role` promete un modelo de interacción. Si no se cumple, estorba.
 *
 * Los dos casos de aquí salieron del lote de interacción de kore-ui, y los dos
 * tienen la misma forma: alguien escribió el rol que describía la PINTA del
 * componente, no lo que el componente hacía.
 *
 * - El carrusel llevaba `role="tablist"` con sus `role="tab"` y **cero**
 *   `role="tabpanel"`. Un lector anunciaba «pestaña 2 de 4» y al activarla no
 *   encontraba ningún panel asociado. Encima, con `numVisible` mayor que uno
 *   cada punto lleva a un GRUPO de diapositivas, así que la relación uno a uno
 *   que un tablist promete no podía existir.
 * - El speed-dial ponía `role="menuitem"` en el `<div>` envoltorio, con el
 *   `<button>` DENTRO. Un menuitem no puede contener un control: la relación se
 *   rompe y la acción deja de anunciarse como activable.
 *
 * El calendario es terreno de sobra para lo mismo: una rejilla de días
 * (`grid`/`gridcell`), eventos que son botones, un selector de vista que parece
 * pestañas y no lleva paneles.
 *
 * Es un cepo para el próximo componente que se invente su semántica. Lo que
 * mide de verdad —qué anuncia un lector— hay que probarlo en un navegador.
 */
it('ninguna pestaña se queda sin panel', function () {
    $problemas = [];

    foreach (vistasDeComponentes() as $nombre => $contenido) {
        if (! str_contains($contenido, 'role="tab"') && ! str_contains($contenido, 'role="tablist"')) {
            continue;
        }

        // El panel puede estar en OTRO archivo del mismo componente: en kore-ui,
        // `tab` reparte la lista en `index.blade.php` y los paneles en
        // `panel.blade.php`. Por eso se mira el componente entero (la carpeta) y
        // no cada vista por separado.
        if (! str_contains($contenido, 'role="tabpanel"')) {
            $problemas[] = sprintf('%s  usa role="tab" sin ningún role="tabpanel" al otro lado', $nombre);
        }
    }

    expect($problemas)->toBe([], implode("\n", array_merge(
        ['Pestañas que no llevan a ningún panel:'],
        $problemas,
        ['', 'Si los indicadores no controlan paneles, son botones: usa aria-current.'],
    )));
});

it('ningún elemento de menú contiene un control', function () {
    $problemas = [];

    $vistas = Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php');

    foreach ($vistas as $vista) {
        $contenido = sinComentariosBlade($vista->getContents());

        // Lo que se mira es la ETIQUETA que lleva el rol, no lo que hay debajo.
        //
        // El primer intento buscaba controles en el cuerpo del elemento, y daba
        // tres falsos positivos: en un menú con varios items seguidos, el cuerpo
        // de uno se comía la apertura del siguiente. Mirar el nombre de la
        // etiqueta responde a la misma pregunta sin ambigüedad.
        foreach (etiquetasConRol($contenido, 'menuitem') as [$linea, $etiqueta]) {
            if (preg_match('/^(button|a|input|select|textarea)$/i', $etiqueta)) {
                continue;
            }

            $problemas[] = sprintf(
                '%s:%d  role="menuitem" en un <%s>, no en el control',
                str_replace(realpath(__DIR__.'/../../').'/', '', $vista->getRealPath()),
                $linea,
                $etiqueta
            );
        }
    }

    expect($problemas)->toBe([], implode("\n", array_merge(
        ['Elementos de menú que envuelven un control en vez de serlo:'],
        $problemas,
        ['', 'Pon role="menuitem" en el <button> o el <a>, y role="none" en el envoltorio.'],
    )));
});

/**
 * Un rol que anuncia una región necesita nombre, o no dice de qué es.
 *
 * `role="dialog"`, `role="menu"`, `role="listbox"`, `role="tree"` y
 * `role="region"` cambian lo que un lector anuncia al llegar: sin `aria-label`
 * ni `aria-labelledby` dice «diálogo», «menú» o «lista» y se queda ahí.
 *
 * En kore-ui salieron tres de una vez al escribir el cepo, y el peor era el del
 * overlay manager: **cada modal de la librería se anunciaba como «diálogo» y
 * nada más**. El nombre lo sabe el componente que se abre, no el manager.
 */
it('todo rol que anuncia una región tiene nombre', function () {
    $problemas = [];

    // Los que cambian lo que se anuncia al entrar. `group` no entra: se usa
    // como agrupador visual en sitios donde el nombre lo da el contexto.
    $conNombre = ['dialog', 'menu', 'listbox', 'tree', 'region', 'navigation', 'search'];

    $vistas = Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php');

    foreach ($vistas as $vista) {
        $contenido = sinComentariosBlade($vista->getContents());
        $lineas = preg_split('/\R/', $contenido);

        foreach ($lineas as $n => $linea) {
            // Un comentario de PHP que MENCIONE un rol no es un rol. Tres de los
            // cuatro falsos positivos de la primera versión salían de aquí: las
            // notas que explican por qué un `role="region"` necesita nombre.
            if (preg_match('/^\s*(\/\/|\*|\/\*)/', $linea)) {
                continue;
            }

            foreach ($conNombre as $rol) {
                if (! preg_match('/(?<![:\w-])role="'.$rol.'"/', $linea)) {
                    continue;
                }

                // El nombre puede estar en cualquier línea de la misma etiqueta:
                // se mira la ventana que va del `<` de apertura a su `>`.
                $desde = $n;
                while ($desde > 0 && ! preg_match('/<[a-zA-Z]/', $lineas[$desde])) {
                    $desde--;
                }

                $etiqueta = '';
                for ($i = $desde; $i < count($lineas) && $i < $desde + 40; $i++) {
                    $etiqueta .= $lineas[$i]."\n";

                    if (str_contains(sinFlechasDePhp($lineas[$i]), '>')) {
                        break;
                    }
                }

                if (preg_match('/aria-label(ledby)?=/', $etiqueta)) {
                    continue;
                }

                $problemas[] = sprintf(
                    '%s:%d  role="%s" sin nombre',
                    str_replace(realpath(__DIR__.'/../../').'/', '', $vista->getRealPath()),
                    $n + 1,
                    $rol
                );
            }
        }
    }

    expect($problemas)->toBe([], implode("\n", array_merge(
        ['Roles que anuncian una región y no dicen de qué es:'],
        $problemas,
        ['', 'Ponles aria-label o aria-labelledby.'],
    )));
});

/** Las vistas agrupadas por componente: un `tab/` es una sola cosa en tres archivos. */
function vistasDeComponentes(): array
{
    $raiz = realpath(__DIR__.'/../../resources/views');
    $porComponente = [];

    foreach (Finder::create()->files()->in($raiz)->name('*.blade.php') as $vista) {
        $relativa = str_replace($raiz.'/', '', $vista->getRealPath());
        $clave = str_contains($relativa, '/')
            ? dirname($relativa)
            : $relativa;

        $porComponente[$clave] = ($porComponente[$clave] ?? '').sinComentariosBlade($vista->getContents());
    }

    return $porComponente;
}

/**
 * Fuera los comentarios de Blade, conservando la numeración de líneas.
 *
 * Uno solo que mencione un `<button>` de ejemplo bastaría para dar por bueno un
 * envoltorio que no lo es —o al revés—.
 */
function sinComentariosBlade(string $contenido): string
{
    return preg_replace_callback(
        '/\{\{--.*?--\}\}/s',
        fn ($c) => str_repeat("\n", substr_count($c[0], "\n")),
        $contenido
    );
}

/**
 * Una línea sin nada que pueda confundirse con el cierre de una etiqueta.
 *
 * Quita las cadenas y, sobre todo, las expresiones de Blade: un
 * `{{ $attributes->except([...]) }}` lleva una flecha de PHP con su `>`, y
 * buscarlo a secas cortaba la etiqueta en su primera línea — dando por ausentes
 * los `aria-label` que venían debajo.
 */
function sinFlechasDePhp(string $linea): string
{
    $limpia = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/', '', $linea);

    return preg_replace('/"[^"]*"|\'[^\']*\'/', '', $limpia);
}

/**
 * Nombre de la etiqueta que lleva un `role` concreto, y en qué línea.
 *
 * Los atributos van uno por línea en estas vistas, así que hay que retroceder
 * desde el `role=` hasta el `<` que abre el elemento. Un `role` dentro de una
 * expresión de Alpine —`:role="..."`— no cuenta: ahí el valor lo decide el
 * componente en tiempo de ejecución.
 *
 * @return array<int, array{int, string}>
 */
function etiquetasConRol(string $contenido, string $rol): array
{
    $salida = [];
    $lineas = preg_split('/\R/', $contenido);

    foreach ($lineas as $n => $linea) {
        if (! preg_match('/(?<![:\w-])role="'.$rol.'"/', $linea)) {
            continue;
        }

        for ($i = $n; $i >= 0 && $i > $n - 40; $i--) {
            if (preg_match('/<([a-zA-Z][a-zA-Z0-9-]*)/', $lineas[$i], $m)) {
                $salida[] = [$i + 1, $m[1]];
                break;
            }
        }
    }

    return $salida;
}
