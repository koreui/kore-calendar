<?php

use KoreUi\Calendar\ICal\ICalException;
use KoreUi\Calendar\ICal\ICalReader;
use KoreUi\Calendar\ICal\ICalText;
use Symfony\Component\Finder\Finder;

/**
 * Los errores y avisos de importar un `.ics` salen en el idioma del calendario.
 *
 * Estaban escritos en español dentro de `src/ICal`: una aplicación en inglés enseñaba
 * «Línea 3: el VEVENT no tiene DTSTART» a quien subía el archivo. La interfaz del
 * calendario ya estaba en `lang/`; esto era lo único que quedaba fuera.
 */
$sinInicio = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a\r\nSUMMARY:x\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

it('el mismo archivo se queja en español o en inglés según el idioma', function (string $idioma, string $esperado) use ($sinInicio) {
    app()->setLocale($idioma);

    $reader = new ICalReader;
    $reader->read($sinInicio);

    expect($reader->errors()[0])->toBe($esperado);
})->with([
    'es' => ['es', 'Línea 3: el VEVENT no tiene DTSTART.'],
    'en' => ['en', 'Line 3: the VEVENT has no DTSTART.'],
]);

it('lo que no es un archivo iCalendar lo dice en el idioma de la aplicación', function () {
    app()->setLocale('en');

    expect(fn () => (new ICalReader)->read('<html></html>'))
        ->toThrow(ICalException::class, 'The content is not an iCalendar file: BEGIN:VCALENDAR was not found.');
});

it('un idioma sin textos cae en el de reserva, no en el nombre de la clave', function () {
    app()->setLocale('fr');
    config()->set('kore-calendar.fallback_locale', 'en');

    expect(ICalText::of('not_utf8'))->toBe('The file is not UTF-8; it was read as Windows-1252.');
});

it('un mensaje se puede sobrescribir desde el config, como cualquier otro texto', function () {
    app()->setLocale('es');
    config()->set('kore-calendar.translations', ['ical_not_utf8' => 'Archivo en otra codificación.']);

    expect(ICalText::of('not_utf8'))->toBe('Archivo en otra codificación.');
});

it('END::name no se come los dos puntos de END:VEVENT', function () {
    app()->setLocale('es');

    expect(ICalText::of('unclosed', ['line' => 4, 'name' => 'VEVENT']))
        ->toBe('Línea 4: el VEVENT no se cierra (falta END:VEVENT).');
});

/**
 * El cepo: ningún mensaje vuelve a escribirse a mano en el lector.
 *
 * `ICalWriter` queda fuera a propósito: sus excepciones son para quien programa (un
 * evento de la aplicación con una fecha que no es una fecha), no para quien sube un archivo.
 */
it('src/ICal no lleva mensajes escritos a mano para quien importa', function () {
    $problemas = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../src/ICal')->name('*.php')->notName(['ICalWriter.php', 'ICalText.php']) as $archivo) {
        foreach (explode("\n", $archivo->getContents()) as $n => $linea) {
            $codigo = trim($linea);

            if ($codigo === '' || str_starts_with($codigo, '*') || str_starts_with($codigo, '//') || str_starts_with($codigo, '/*')) {
                continue;
            }

            // Un literal que empieza como empezaban todos, o un warn()/ICalException con texto a pelo.
            if (preg_match('/["\'](Línea |El archivo|El contenido)/u', $codigo)
                || preg_match('/(->warn|->fail|new ICalException)\(\s*["\']/u', $codigo)) {
                $problemas[] = $archivo->getFilename().':'.($n + 1).'  '.mb_substr($codigo, 0, 80);
            }
        }
    }

    expect($problemas)->toBe([], "Mensajes escritos a mano en src/ICal:\n".implode("\n", $problemas));
});
