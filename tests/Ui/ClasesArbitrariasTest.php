<?php

use Symfony\Component\Finder\Finder;

/**
 * Valores arbitrarios de Tailwind que el compilador nunca llega a ver.
 *
 * Tailwind v4 extrae las clases del texto del archivo partiendo por espacios en
 * blanco. Un valor arbitrario que contenga un espacio —o una comilla escapada,
 * que mete la barra invertida dentro del candidato— se corta y la utilidad no se
 * genera. No hay error de compilación ni regla en el CSS: la clase está en el
 * HTML y no hace nada.
 *
 * Pasó en kore-ui con la palomita de `<x-kore::checkbox>`, escrita como un SVG en
 * línea con `viewBox='0 0 16 16'` dentro: una casilla marcada era un cuadrado de
 * color liso, sin palomita, y llevaba así desde que se escribió el componente. Un
 * `assertSee` de la clase habría pasado, porque la clase sí estaba.
 *
 * El calendario coloca eventos y franjas con `style` (porcentajes calculados en
 * el servidor), pero el día que alguien lo pase a una clase como
 * `top-[calc(100% - 2rem)]`, con espacios dentro, esa clase no existirá.
 *
 * Esto es un cepo para el próximo, no el test del arreglo: ese mide el
 * `background-image` o la posición en un navegador.
 */
it('no deja valores arbitrarios que Tailwind no pueda extraer', function () {
    $problemas = [];

    $vistas = Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php');

    foreach ($vistas as $vista) {
        foreach (preg_split('/\R/', $vista->getContents()) as $n => $linea) {
            // El nombre de la utilidad termina en `-` antes del corchete
            // (`bg-[`, `min-w-[`, `checked:bg-[`). Así queda fuera el indexado
            // de arrays de PHP y de JavaScript, que no es una clase.
            preg_match_all('/(?<![\w$])[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?::[a-z-]+)*-\[[^\]]*\]/i', $linea, $encontrados);

            foreach ($encontrados[0] as $clase) {
                $valor = substr($clase, strpos($clase, '[') + 1, -1);

                if (str_contains($valor, ' ') || str_contains($valor, '\\"') || str_contains($valor, "\\'")) {
                    $problemas[] = sprintf(
                        '%s:%d  %s',
                        $vista->getFilename(),
                        $n + 1,
                        mb_strimwidth($clase, 0, 90, '…')
                    );
                }
            }
        }
    }

    expect($problemas)->toBe([], "Valores arbitrarios que Tailwind no va a generar:\n".implode("\n", $problemas));
});
