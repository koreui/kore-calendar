<?php

use Symfony\Component\Finder\Finder;

/**
 * Ningún texto de interfaz escrito a mano dentro de una vista.
 *
 * En kore-ui la librería llegó a mezclar idiomas en la misma pantalla: el botón
 * decía «Añadir» y el desplegable de al lado, «No options found». Y no era solo
 * el idioma — un texto incrustado en el Blade **no se puede cambiar sin publicar
 * la vista**, así que quien montara la librería en inglés, en catalán o en
 * portugués tenía que copiar los componentes enteros.
 *
 * Todo lo que llega al usuario —`aria-label`, `placeholder`, `title`,
 * `aria-roledescription` y los valores por defecto de las props de texto— sale
 * de `Messages` (`lang/{es,en}/calendar.php`, con el override `translations` del config).
 *
 * Este cepo mira lo contrario de lo que parece: no busca palabras inglesas —esa
 * lista siempre se queda corta— sino **literales sin interpolar**. Da igual el
 * idioma: si el texto está escrito a mano en la vista, no se puede traducir.
 */
it('ningún texto de interfaz está incrustado en una vista', function () {
    $problemas = [];

    // Lo que sí puede ir literal, porque no es texto que nadie lea:
    //   · valores de atributos que son datos («#000000», «0 0 24 24»)
    //   · fragmentos de expresiones de Alpine que el regex parte por la mitad
    $exentos = '/^(#[0-9a-fA-F]{3,8}|[\d\s.,-]+|.*(===|\?|@js\(|\(item\)|\(\)).*)$/';

    $vistas = Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php');

    foreach ($vistas as $vista) {
        // Se quitan los comentarios, pero dejando sus saltos de línea: si no,
        // el número que se informa no es el de la línea que falla.
        $contenido = preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            fn ($m) => str_repeat("\n", substr_count($m[0], "\n")),
            $vista->getContents(),
        );
        $relativa = str_replace(realpath(__DIR__.'/../../').'/', '', $vista->getRealPath());

        foreach (preg_split('/\R/', $contenido) as $n => $linea) {
            // Un literal es lo que NO lleva `{{ }}`, `$` ni `@js(` dentro.
            //
            // El `(?<![:.\w])` de delante descarta los bindings de Alpine:
            // `:placeholder="currentPlaceholder"` acaba en «placeholder» y el
            // valor es una EXPRESIÓN, no un texto.
            $patron = '/(?<![:.\w-])(aria-label|placeholder|aria-roledescription)=["\']([^"\'{}$]+?)["\']/';

            if (! preg_match_all($patron, $linea, $coincidencias, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($coincidencias as [, $atributo, $texto]) {
                if (preg_match($exentos, trim($texto))) {
                    continue;
                }

                $problemas[] = sprintf('%s:%d  %s="%s"', $relativa, $n + 1, $atributo, $texto);
            }
        }
    }

    expect($problemas)->toBe([], implode("\n", array_merge(
        ['Texto de interfaz que no se puede traducir sin publicar la vista:'],
        $problemas,
        ['', 'Sácalo a lang/{es,en}/calendar.php y tráelo con $calendar->text(…).'],
    )));
});

/**
 * Y lo mismo cuando el texto va PEGADO a una interpolación.
 *
 * El primer cepo exige que el valor no lleve `{{ }}` ni `$` dentro, y esa es
 * justo la rendija: `aria-label="Ordenar por {{ $columna }}"` lleva las dos
 * cosas, así que quedaba exento entero — y con él «Ordenar por», que no se podía
 * traducir en una librería que ya lo traducía todo.
 *
 * Aquí se mira lo contrario: se **quita** lo interpolado y se juzga lo que
 * queda. Un espacio, dos puntos o un paréntesis no son texto; dos letras
 * seguidas, sí.
 */
it('ningún texto de interfaz va pegado a una interpolación', function () {
    $problemas = [];

    $vistas = Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php');

    foreach ($vistas as $vista) {
        $contenido = preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            fn ($m) => str_repeat("\n", substr_count($m[0], "\n")),
            $vista->getContents(),
        );
        $relativa = str_replace(realpath(__DIR__.'/../../').'/', '', $vista->getRealPath());

        foreach (preg_split('/\R/', $contenido) as $n => $linea) {
            $patron = '/(?<![:.\w-])(aria-label|placeholder|title|aria-roledescription)="([^"]*\{\{[^"]*)"/';

            if (! preg_match_all($patron, $linea, $coincidencias, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($coincidencias as [, $atributo, $valor]) {
                // Fuera lo interpolado y las directivas: lo que sobra es lo que
                // está escrito a mano.
                $literal = preg_replace('/\{\{.*?\}\}/', '', $valor);
                $literal = preg_replace('/@\w+(\(.*?\))?/', '', $literal);

                if (! preg_match('/\p{L}{2,}/u', $literal)) {
                    continue;
                }

                $problemas[] = sprintf('%s:%d  %s="%s"', $relativa, $n + 1, $atributo, trim($valor));
            }
        }
    }

    expect($problemas)->toBe([], implode("\n", array_merge(
        ['Texto de interfaz escrito junto a una interpolación:'],
        $problemas,
        ['', 'Saca la frase ENTERA a lang/{es,en}/calendar.php, con un :marcador dentro.'],
    )));
});

/**
 * Y lo mismo dentro de un binding de Alpine.
 *
 * El cepo de arriba descarta `x-bind:aria-label` y `:aria-label` a propósito: su
 * valor es una EXPRESIÓN, no un texto, y mirarla como si lo fuera daba falsos
 * positivos en cada `:placeholder="currentPlaceholder"`. El agujero es que una
 * expresión también puede llevar el texto dentro, entre comillas — y por ahí se
 * coló en kore-ui el botón del ojo de `password`, que anunciaba «Show password» y
 * «Hide password» en una librería que responde en español, sin forma de
 * traducirlo.
 *
 * Aquí se mira solo lo que hay ENTRE COMILLAS dentro de la expresión. Un texto
 * que viene de `@js(config(...))` no lleva comillas propias y no salta.
 */
it('ningún texto de interfaz está incrustado en un binding de Alpine', function () {
    $problemas = [];

    $vistas = Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php');

    foreach ($vistas as $vista) {
        // Se quitan los comentarios, pero dejando sus saltos de línea: si no,
        // el número que se informa no es el de la línea que falla.
        $contenido = preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            fn ($m) => str_repeat("\n", substr_count($m[0], "\n")),
            $vista->getContents(),
        );
        $relativa = str_replace(realpath(__DIR__.'/../../').'/', '', $vista->getRealPath());

        foreach (preg_split('/\R/', $contenido) as $n => $linea) {
            // `x-bind:aria-label="…"` o `:aria-label="…"`, con la expresión entera.
            $patron = '/(?:x-bind)?:(aria-label|aria-roledescription|placeholder)="([^"]*)"/';

            if (! preg_match_all($patron, $linea, $coincidencias, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($coincidencias as [, $atributo, $expresion]) {
                // Fuera lo que ya viene de PHP: `@js(config('…', 'Mes anterior'))`
                // lleva su valor por defecto entre comillas, pero ese texto SÍ se
                // puede traducir. El `(?1)` recorre los paréntesis anidados.
                $expresion = preg_replace('/@js(\((?:[^()]++|(?1))*\))/', '', $expresion);

                // Y lo mismo con `{{ __('…') }}`: es PHP interpolado, traducible.
                $expresion = preg_replace('/\{\{.*?\}\}/', '', $expresion);

                // Lo que va entre comillas simples DENTRO de la expresión.
                if (! preg_match_all("/'([^']*)'/", $expresion, $cadenas)) {
                    continue;
                }

                foreach ($cadenas[1] as $texto) {
                    // Una cadena de una expresión no siempre es texto: también
                    // se comparan modos (`'year'`), se nombran clases y se
                    // interpolan marcadores (`':item'`).
                    if (! preg_match('/\p{L}{2,}\s+\p{L}/u', $texto)) {
                        continue;
                    }

                    $problemas[] = sprintf('%s:%d  %s="…%s…"', $relativa, $n + 1, $atributo, $texto);
                }
            }
        }
    }

    expect($problemas)->toBe([], implode("\n", array_merge(
        ['Texto de interfaz escrito dentro de una expresión de Alpine:'],
        $problemas,
        ['', 'Sácalo a lang/{es,en}/calendar.php y tráelo con @js($calendar->text(…)).'],
    )));
});

/**
 * Y las props de texto tampoco pueden traer su valor por defecto escrito.
 *
 * `'filterPlaceholder' => 'Filter...'` en el `@props` es igual de intraducible
 * que un literal en el atributo, y encima cuesta más de ver.
 */
it('ninguna prop de texto trae su valor por defecto escrito a mano', function () {
    $problemas = [];

    // Props cuyo nombre acaba como uno de texto pero que no lo son (en kore-ui,
    // `optionLabel` y `optionValue`: el NOMBRE de la clave que el componente busca
    // dentro de cada opción). El calendario todavía no tiene ninguna.
    $noSonTexto = [];

    $vistas = Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php');

    foreach ($vistas as $vista) {
        $contenido = $vista->getContents();
        $relativa = str_replace(realpath(__DIR__.'/../../').'/', '', $vista->getRealPath());

        // Solo dentro del bloque `@props([...])`.
        if (! preg_match('/@props\(\[(.*?)\]\)/s', $contenido, $m)) {
            continue;
        }

        $patron = "/'(\w*(?:[Ll]abel|[Pp]laceholder|[Tt]ext|[Tt]itle))'\s*=>\s*'([^']+)'/";

        if (! preg_match_all($patron, $m[1], $coincidencias, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($coincidencias as [, $prop, $valor]) {
            if (in_array($prop, $noSonTexto, true)) {
                continue;
            }

            $problemas[] = sprintf('%s  %s => \'%s\'', $relativa, $prop, $valor);
        }
    }

    expect($problemas)->toBe([], implode("\n", array_merge(
        ['Props de texto con el valor por defecto escrito en la vista:'],
        $problemas,
        ['', 'Pon null y resuélvelo con Messages::get(…) dentro del @php.'],
    )));
});
