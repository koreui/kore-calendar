<?php

use Symfony\Component\Finder\Finder;

/**
 * Un panel teleportado a `<body>` tiene que poder recibir el teclado.
 *
 * `x-teleport` conserva el ÁMBITO de Alpine —el panel sigue viendo el estado y
 * los `$refs` de su componente— pero no el árbol DOM: el nodo se mueve a
 * `<body>`, así que los eventos que ocurren dentro no burbujean por la raíz del
 * componente. Un `x-on:keydown` puesto solo en la raíz deja de recibir nada en
 * cuanto el foco entra en el panel.
 *
 * Y el foco entra: en kore-ui `<x-kore::select searchable>` lo lleva a su caja de
 * búsqueda al abrir, y `<x-kore::dropdown>` al primer item con la primera flecha.
 * Los dos quedaban abiertos y sordos —ni flechas, ni Enter, ni Escape— y no había
 * forma de verlo desde el HTML: la marca estaba, el panel se pintaba, y solo el
 * teclado dejaba de funcionar. Un popover de evento o un selector de fecha del
 * calendario sufriría lo mismo.
 *
 * Dos salidas cuentan como válidas, y las dos están medidas:
 *
 *   1. El panel lleva su propio `x-on:keydown`.
 *   2. El componente escucha en `window` (`x-on:keydown.escape.window`), que
 *      recibe el evento esté el foco donde esté. Es la salida menos buena: en
 *      `window` el orden lo decide quién se registró antes, así que un panel
 *      abierto dentro de un modal compite con el overlay manager por la misma
 *      tecla. Se acepta, pero escuchar en el elemento es preferible.
 *
 * Un panel sin NADA enfocable dentro —un tooltip— no entra en el reparto:
 * el foco no puede llegar hasta él.
 *
 * Es un cepo para el próximo componente flotante. Hoy el calendario no teleporta
 * nada (la prueba técnica de la fase 1 solo escucha en `window`), así que el
 * test pasa sin recorrer ningún panel.
 */
it('todo panel teleportado con controles dentro puede recibir el teclado', function () {
    $problemas = [];

    $vistas = Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php');

    foreach ($vistas as $vista) {
        $contenido = $vista->getContents();

        if (! str_contains($contenido, 'x-teleport')) {
            continue;
        }

        // Salida nº 2: el componente escucha en window, así que da igual dónde
        // esté el foco cuando se pulsa la tecla.
        if (preg_match('/(x-on:keydown|@keydown)[^"\']*\.window/', $contenido)) {
            continue;
        }

        foreach (nodosTeleportados($contenido) as [$linea, $apertura, $cuerpo]) {
            // Salida nº 1.
            if (str_contains($apertura, 'x-on:keydown') || str_contains($apertura, '@keydown')) {
                continue;
            }

            // Sin nada que enfocar dentro, el foco no puede llegar al panel.
            if (! preg_match('/<(button|input|select|textarea|a\s)|tabindex="0"/i', $cuerpo)) {
                continue;
            }

            $problemas[] = sprintf(
                '%s:%d  panel teleportado con controles dentro y sin manejador de teclado',
                str_replace(realpath(__DIR__.'/../../').'/', '', $vista->getRealPath()),
                $linea
            );
        }
    }

    expect($problemas)->toBe([], implode("\n", array_merge(
        ['Paneles que se mueven a <body> y dejan de recibir teclas:'],
        $problemas,
        ['', 'Pon x-on:keydown en el nodo teleportado, o escucha en window desde la raíz.'],
    )));
});

/**
 * Nodos teleportados de una vista: dónde empiezan, su etiqueta de apertura y su
 * contenido hasta el cierre del `<template>`.
 *
 * @return array<int, array{int, string, string}>
 */
function nodosTeleportados(string $contenido): array
{
    $salida = [];

    // Fuera los comentarios de Blade ANTES de partir en líneas: uno solo que
    // mencione `<body>` mete un `>` en mitad de la etiqueta y el recorte se
    // corta antes de llegar a los atributos de verdad. Se sustituyen por líneas
    // en blanco para no descuadrar la numeración.
    $sinComentarios = preg_replace_callback(
        '/\{\{--.*?--\}\}/s',
        fn ($c) => str_repeat("\n", substr_count($c[0], "\n")),
        $contenido
    );
    $lineas = preg_split('/\R/', $sinComentarios);

    foreach ($lineas as $n => $linea) {
        if (! str_contains($linea, 'x-teleport')) {
            continue;
        }

        // Los atributos van uno por línea en estas vistas: se acumula desde la
        // siguiente hasta cerrar la etiqueta del primer elemento.
        //
        // El `>` que cierra hay que buscarlo FUERA de las comillas: un
        // `aria-labelledby="...{{ $this->getId() }}"` lleva una flecha de PHP
        // dentro, y buscar el carácter a secas cortaba la etiqueta por la mitad
        // —dando por ausentes atributos que sí estaban—.
        $apertura = '';
        $fin = null;
        for ($i = $n + 1; $i < count($lineas) && $i < $n + 40; $i++) {
            $apertura .= $lineas[$i]."\n";

            $fuera = preg_replace('/"[^"]*"|\'[^\']*\'/', '', $lineas[$i]);

            if (str_contains($fuera, '>')) {
                $fin = $i;
                break;
            }
        }

        if ($fin === null) {
            continue;
        }

        $cuerpo = '';
        for ($i = $fin + 1; $i < count($lineas); $i++) {
            if (str_contains($lineas[$i], '</template>')) {
                break;
            }
            $cuerpo .= $lineas[$i]."\n";
        }

        $salida[] = [$n + 1, $apertura, $cuerpo];
    }

    return $salida;
}
